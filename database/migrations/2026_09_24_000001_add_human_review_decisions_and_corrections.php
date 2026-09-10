<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::statement('ALTER TABLE reviews DROP CONSTRAINT IF EXISTS reviews_status_check');
        DB::statement("ALTER TABLE reviews ADD CONSTRAINT reviews_status_check CHECK (status IN ('in_progress','approved','changes_requested','escalated','rejected'))");

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION human_review_history_guard() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'HUMAN_REVIEW_HISTORY_IMMUTABLE';
                END IF;
                IF OLD.request_id IS DISTINCT FROM NEW.request_id
                   OR OLD.assignment_id IS DISTINCT FROM NEW.assignment_id
                   OR OLD.version_id IS DISTINCT FROM NEW.version_id
                   OR OLD.checklist_version_id IS DISTINCT FROM NEW.checklist_version_id
                   OR OLD.reviewer_id IS DISTINCT FROM NEW.reviewer_id
                   OR OLD.started_at IS DISTINCT FROM NEW.started_at
                   OR OLD.created_at IS DISTINCT FROM NEW.created_at THEN
                    RAISE EXCEPTION 'HUMAN_REVIEW_IDENTITY_IMMUTABLE';
                END IF;
                IF OLD.status <> 'in_progress' AND OLD IS DISTINCT FROM NEW THEN
                    RAISE EXCEPTION 'HUMAN_REVIEW_TERMINAL_IMMUTABLE';
                END IF;
                IF OLD.status = 'in_progress' AND NEW.status NOT IN ('in_progress','approved','changes_requested','escalated','rejected') THEN
                    RAISE EXCEPTION 'HUMAN_REVIEW_INVALID_TRANSITION';
                END IF;
                RETURN NEW;
            END;
            $$;
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION correction_input_manifest_v1_is_valid(manifest jsonb) RETURNS boolean
            LANGUAGE plpgsql IMMUTABLE AS $$
            DECLARE
                key text;
                source_kind text;
            BEGIN
                IF manifest IS NULL OR jsonb_typeof(manifest) <> 'object' THEN
                    RETURN false;
                END IF;
                IF (manifest - 'schema_version' - 'request_id' - 'input_revision' - 'source_kind'
                    - 'source_version_id' - 'source_content_hash' - 'source_audit_execution_id'
                    - 'source_audit_report_hash' - 'source_review_id' - 'source_review_payload_hash'
                    - 'section_keys' - 'correction_round' - 'correlation_id') <> '{}'::jsonb THEN
                    RETURN false;
                END IF;

                source_kind := COALESCE(NULLIF(manifest->>'source_kind', ''), 'audit');
                IF source_kind NOT IN ('audit','human_review') THEN
                    RETURN false;
                END IF;

                IF NULLIF(manifest->>'schema_version', '')::integer IS DISTINCT FROM 1
                   OR COALESCE(NULLIF(manifest->>'request_id', '')::bigint, 0) < 1
                   OR NULLIF(manifest->>'input_revision', '')::integer IS NULL
                   OR COALESCE(NULLIF(manifest->>'source_version_id', '')::bigint, 0) < 1
                   OR COALESCE(NULLIF(manifest->>'source_audit_execution_id', '')::bigint, 0) < 1
                   OR NULLIF(manifest->>'correction_round', '')::integer IS NULL
                   OR NULLIF(manifest->>'correction_round', '')::integer < 1
                   OR manifest->>'source_content_hash' !~ '^[0-9a-f]{64}$'
                   OR manifest->>'source_audit_report_hash' !~ '^[0-9a-f]{64}$'
                   OR jsonb_typeof(manifest->'section_keys') IS DISTINCT FROM 'array'
                   OR jsonb_array_length(manifest->'section_keys') < 1
                   OR manifest->>'correlation_id' !~* '^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$' THEN
                    RETURN false;
                END IF;

                IF source_kind = 'audit' THEN
                    IF manifest ? 'source_review_id' OR manifest ? 'source_review_payload_hash' THEN
                        RETURN false;
                    END IF;
                ELSE
                    IF COALESCE(NULLIF(manifest->>'source_review_id', '')::bigint, 0) < 1
                       OR manifest->>'source_review_payload_hash' !~ '^[0-9a-f]{64}$' THEN
                        RETURN false;
                    END IF;
                END IF;

                FOR key IN SELECT jsonb_array_elements_text(manifest->'section_keys') LOOP
                    IF key NOT IN ('planning','pedagogical_design','sessions','assessment_plan','resources','adaptation_notes') THEN
                        RETURN false;
                    END IF;
                END LOOP;
                RETURN true;
            EXCEPTION WHEN others THEN
                RETURN false;
            END;
            $$;
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION human_review_integrity_check(target_id bigint) RETURNS void
            LANGUAGE plpgsql AS $$
            DECLARE
                rv reviews;
                a review_assignments;
                r planning_requests;
                current_version bigint;
                missing_required integer;
                failed_required integer;
                correction_id bigint;
            BEGIN
                SELECT * INTO rv FROM reviews WHERE id = target_id;
                IF NOT FOUND THEN RETURN; END IF;

                SELECT * INTO a FROM review_assignments WHERE id = rv.assignment_id FOR UPDATE;
                IF NOT FOUND OR a.request_id IS DISTINCT FROM rv.request_id OR a.reviewer_id IS DISTINCT FROM rv.reviewer_id THEN
                    RAISE EXCEPTION 'HUMAN_REVIEW_ASSIGNMENT_MISMATCH';
                END IF;
                SELECT * INTO r FROM planning_requests WHERE id = rv.request_id FOR UPDATE;
                IF NOT FOUND THEN RAISE EXCEPTION 'HUMAN_REVIEW_REQUEST_REQUIRED'; END IF;
                SELECT current_version_id INTO current_version FROM documents WHERE request_id = rv.request_id;
                IF NOT EXISTS (
                    SELECT 1 FROM document_versions dv
                    JOIN documents d ON d.id = dv.document_id
                    WHERE dv.id = rv.version_id AND d.request_id = rv.request_id
                ) THEN
                    RAISE EXCEPTION 'HUMAN_REVIEW_VERSION_REQUEST_MISMATCH';
                END IF;
                IF NOT EXISTS (
                    SELECT 1 FROM review_checklist_versions cv
                    WHERE cv.id = rv.checklist_version_id AND cv.status = 'published'
                ) THEN
                    RAISE EXCEPTION 'HUMAN_REVIEW_CHECKLIST_NOT_PUBLISHED';
                END IF;

                IF rv.status = 'in_progress' THEN
                    IF current_version IS DISTINCT FROM rv.version_id THEN
                        RAISE EXCEPTION 'HUMAN_REVIEW_VERSION_STALE';
                    END IF;
                    IF a.status <> 'in_progress' OR r.status <> 'REVISION_HUMANA' THEN
                        RAISE EXCEPTION 'HUMAN_REVIEW_ACTIVE_STATE_MISMATCH';
                    END IF;
                    RETURN;
                END IF;

                IF rv.status = 'approved' THEN
                    IF a.status <> 'completed' OR r.status <> 'APROBADA' THEN
                        RAISE EXCEPTION 'HUMAN_REVIEW_APPROVED_STATE_MISMATCH';
                    END IF;
                    SELECT count(*) INTO missing_required
                    FROM review_checklist_items i
                    LEFT JOIN review_checklist_responses rr
                      ON rr.review_id = rv.id AND rr.checklist_item_id = i.id AND rr.passed = true
                    WHERE i.checklist_version_id = rv.checklist_version_id
                      AND i.required = true
                      AND rr.id IS NULL;
                    IF missing_required > 0 THEN
                        RAISE EXCEPTION 'HUMAN_REVIEW_REQUIRED_CHECKLIST_INCOMPLETE';
                    END IF;
                    IF NOT EXISTS (
                        SELECT 1 FROM approvals ap
                        WHERE ap.review_id = rv.id AND ap.kind = 'human'
                          AND ap.request_id = rv.request_id AND ap.version_id = rv.version_id
                    ) THEN
                        RAISE EXCEPTION 'HUMAN_REVIEW_APPROVAL_REQUIRED';
                    END IF;
                    RETURN;
                END IF;

                IF rv.status = 'changes_requested' THEN
                    IF a.status <> 'completed'
                       OR a.ended_reason IS DISTINCT FROM 'human_review_changes_requested'
                       OR r.status <> 'CORRECCION_IA' THEN
                        RAISE EXCEPTION 'HUMAN_REVIEW_CHANGES_STATE_MISMATCH';
                    END IF;
                    SELECT count(*) INTO failed_required
                    FROM review_checklist_items i
                    LEFT JOIN review_checklist_responses rr
                      ON rr.review_id = rv.id AND rr.checklist_item_id = i.id
                    WHERE i.checklist_version_id = rv.checklist_version_id
                      AND i.required = true
                      AND (rr.id IS NULL OR rr.passed = false);
                    IF failed_required < 1 THEN
                        RAISE EXCEPTION 'HUMAN_REVIEW_CHANGES_FAILED_ITEM_REQUIRED';
                    END IF;
                    SELECT ae.id INTO correction_id
                    FROM ai_executions ae
                    WHERE ae.request_id = rv.request_id
                      AND ae.stage = 'correction'
                      AND ae.input_manifest->>'source_kind' = 'human_review'
                      AND ae.input_manifest->>'source_review_id' = rv.id::text
                      AND ae.input_manifest->>'source_version_id' = rv.version_id::text
                      AND ae.status IN ('pending','waiting_manual','running')
                    ORDER BY ae.id DESC LIMIT 1;
                    IF correction_id IS NULL THEN
                        RAISE EXCEPTION 'HUMAN_REVIEW_CORRECTION_EXECUTION_REQUIRED';
                    END IF;
                    IF NOT EXISTS (
                        SELECT 1 FROM outbox_events oe
                        WHERE oe.event_key = 'ai-execution:' || correction_id || ':correction-dispatch'
                          AND oe.type = 'planning.correction.requested'
                          AND oe.aggregate_id = rv.request_id
                    ) THEN
                        RAISE EXCEPTION 'HUMAN_REVIEW_CORRECTION_OUTBOX_REQUIRED';
                    END IF;
                    IF NOT EXISTS (
                        SELECT 1 FROM request_state_events e
                        WHERE e.request_id = rv.request_id
                          AND e.from_status = 'REVISION_HUMANA'
                          AND e.to_status = 'CORRECCION_IA'
                          AND e.reason = 'human_review_changes_requested'
                          AND e.actor_id = rv.reviewer_id
                    ) THEN
                        RAISE EXCEPTION 'HUMAN_REVIEW_CHANGES_STATE_EVENT_REQUIRED';
                    END IF;
                    RETURN;
                END IF;

                IF rv.status IN ('escalated','rejected') THEN
                    IF a.status <> 'cancelled' OR r.status <> 'REVISION_HUMANA' THEN
                        RAISE EXCEPTION 'HUMAN_REVIEW_ADMIN_DECISION_STATE_MISMATCH';
                    END IF;
                    IF a.ended_reason IS DISTINCT FROM ('human_review_' || rv.status) THEN
                        RAISE EXCEPTION 'HUMAN_REVIEW_ADMIN_DECISION_REASON_MISMATCH';
                    END IF;
                    IF NOT EXISTS (
                        SELECT 1 FROM request_blocks b
                        WHERE b.request_id = rv.request_id
                          AND b.code = 'human_review_attention'
                          AND b.stage = 'human_review'
                          AND b.resolved_at IS NULL
                          AND b.details->>'review_id' = rv.id::text
                          AND b.details->>'decision' = rv.status
                    ) THEN
                        RAISE EXCEPTION 'HUMAN_REVIEW_ADMIN_BLOCK_REQUIRED';
                    END IF;
                    RETURN;
                END IF;
            END;
            $$;
        SQL);

        // La ruta CORRECCION_IA ahora acepta como origen un audit fallido (4E)
        // o una review humana que pidió cambios sobre una versión con audit aprobado.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION planning_post_audit_routing_integrity_check(target_id bigint) RETURNS void
            LANGUAGE plpgsql AS $$
            DECLARE
                parent planning_requests;
                current_version_id bigint;
                current_content_hash char(64);
                audit_id bigint;
                audit_report_value jsonb;
                correction_id bigint;
                correction_status text;
                correction_source_kind text;
                correction_source_audit_id bigint;
                correction_review_id bigint;
                correction_correlation text;
                rv reviews;
                ra review_assignments;
            BEGIN
                SELECT * INTO parent FROM planning_requests WHERE id = target_id FOR UPDATE;
                IF NOT FOUND OR parent.commercial_authorized_at IS NULL THEN
                    RETURN;
                END IF;

                IF parent.status NOT IN (
                    'CORRECCION_IA','REVISION_HUMANA','APROBADA','GENERANDO_DOCUMENTO',
                    'LISTA_PARA_ENTREGAR','ENTREGADA','COMPLETADA','CORRECCION_SOLICITADA'
                ) THEN
                    RETURN;
                END IF;

                SELECT d.current_version_id, dv.content_hash
                INTO current_version_id, current_content_hash
                FROM documents d
                JOIN document_versions dv ON dv.id = d.current_version_id
                WHERE d.request_id = parent.id;

                IF current_version_id IS NULL OR current_content_hash IS NULL THEN
                    RETURN;
                END IF;

                SELECT ae.id, ae.audit_report
                INTO audit_id, audit_report_value
                FROM ai_executions ae
                WHERE ae.request_id = parent.id
                  AND ae.stage = 'audit'
                  AND ae.status = 'succeeded'
                  AND ae.input_revision = parent.input_revision
                  AND ae.input_manifest->>'source_version_id' = current_version_id::text
                  AND ae.input_manifest->>'source_content_hash' = current_content_hash
                ORDER BY ae.id DESC
                LIMIT 1;

                IF audit_id IS NULL OR audit_report_value IS NULL OR NOT audit_result_v1_is_valid(audit_report_value) THEN
                    RETURN;
                END IF;

                IF parent.status = 'CORRECCION_IA' THEN
                    SELECT ae.id, ae.status,
                           COALESCE(NULLIF(ae.input_manifest->>'source_kind',''), 'audit'),
                           NULLIF(ae.input_manifest->>'source_audit_execution_id','')::bigint,
                           NULLIF(ae.input_manifest->>'source_review_id','')::bigint,
                           ae.input_manifest->>'correlation_id'
                    INTO correction_id, correction_status, correction_source_kind,
                         correction_source_audit_id, correction_review_id, correction_correlation
                    FROM ai_executions ae
                    WHERE ae.request_id = parent.id
                      AND ae.stage = 'correction'
                      AND ae.input_revision = parent.input_revision
                      AND ae.input_manifest->>'source_version_id' = current_version_id::text
                      AND ae.input_manifest->>'source_content_hash' = current_content_hash
                    ORDER BY ae.id DESC
                    LIMIT 1;

                    IF correction_id IS NULL OR correction_status NOT IN ('pending','waiting_manual','running') THEN
                        RAISE EXCEPTION 'AI_CORRECTION_EXECUTION_REQUIRED';
                    END IF;
                    IF correction_source_audit_id IS DISTINCT FROM audit_id THEN
                        RAISE EXCEPTION 'AI_CORRECTION_SOURCE_AUDIT_MISMATCH';
                    END IF;

                    IF correction_source_kind = 'audit' THEN
                        IF (audit_report_value->>'passed')::boolean IS DISTINCT FROM false THEN
                            RAISE EXCEPTION 'AI_CORRECTION_REQUIRES_FAILED_AUDIT';
                        END IF;
                        IF NOT EXISTS (
                            SELECT 1 FROM request_state_events rse
                            WHERE rse.request_id = parent.id
                              AND rse.from_status = 'AUDITORIA_IA'
                              AND rse.to_status = 'CORRECCION_IA'
                              AND rse.reason = 'audit_failed_correction_dispatched'
                              AND rse.correlation_id::text = correction_correlation
                        ) THEN
                            RAISE EXCEPTION 'AI_CORRECTION_STATE_EVENT_REQUIRED';
                        END IF;
                    ELSIF correction_source_kind = 'human_review' THEN
                        IF (audit_report_value->>'passed')::boolean IS DISTINCT FROM true THEN
                            RAISE EXCEPTION 'AI_HUMAN_CORRECTION_REQUIRES_PASSED_AUDIT';
                        END IF;
                        SELECT * INTO rv FROM reviews WHERE id = correction_review_id;
                        IF NOT FOUND OR rv.request_id IS DISTINCT FROM parent.id
                           OR rv.version_id IS DISTINCT FROM current_version_id
                           OR rv.status IS DISTINCT FROM 'changes_requested' THEN
                            RAISE EXCEPTION 'AI_HUMAN_CORRECTION_REVIEW_REQUIRED';
                        END IF;
                        SELECT * INTO ra FROM review_assignments WHERE id = rv.assignment_id;
                        IF NOT FOUND OR ra.status IS DISTINCT FROM 'completed'
                           OR ra.ended_reason IS DISTINCT FROM 'human_review_changes_requested' THEN
                            RAISE EXCEPTION 'AI_HUMAN_CORRECTION_ASSIGNMENT_REQUIRED';
                        END IF;
                        IF NOT EXISTS (
                            SELECT 1 FROM request_state_events rse
                            WHERE rse.request_id = parent.id
                              AND rse.from_status = 'REVISION_HUMANA'
                              AND rse.to_status = 'CORRECCION_IA'
                              AND rse.reason = 'human_review_changes_requested'
                              AND rse.actor_id = rv.reviewer_id
                              AND rse.correlation_id::text = correction_correlation
                        ) THEN
                            RAISE EXCEPTION 'AI_HUMAN_CORRECTION_STATE_EVENT_REQUIRED';
                        END IF;
                    ELSE
                        RAISE EXCEPTION 'AI_CORRECTION_SOURCE_KIND_INVALID';
                    END IF;

                    IF NOT EXISTS (
                        SELECT 1 FROM outbox_events oe
                        WHERE oe.event_key = 'ai-execution:' || correction_id || ':correction-dispatch'
                          AND oe.type = 'planning.correction.requested'
                          AND oe.aggregate_id = parent.id
                          AND oe.payload->>'ai_execution_id' = correction_id::text
                          AND oe.payload->>'source_version_id' = current_version_id::text
                    ) THEN
                        RAISE EXCEPTION 'AI_CORRECTION_OUTBOX_REQUIRED';
                    END IF;

                    IF correction_status IN ('waiting_manual','running') AND NOT EXISTS (
                        SELECT 1 FROM ai_manual_packages WHERE ai_execution_id = correction_id
                    ) THEN
                        RAISE EXCEPTION 'AI_CORRECTION_MANUAL_PACKAGE_REQUIRED';
                    END IF;
                    RETURN;
                END IF;

                IF (audit_report_value->>'passed')::boolean IS DISTINCT FROM true THEN
                    RAISE EXCEPTION 'AI_POST_AUDIT_PASS_REQUIRED';
                END IF;

                IF NOT EXISTS (
                    SELECT 1 FROM approvals a
                    WHERE a.request_id = parent.id
                      AND a.version_id = current_version_id
                      AND a.kind = 'ai'
                      AND a.ai_execution_id = audit_id
                ) THEN
                    RAISE EXCEPTION 'AI_APPROVAL_REQUIRED';
                END IF;

                IF parent.status = 'REVISION_HUMANA' THEN
                    IF parent.human_review_required_snapshot IS DISTINCT FROM true THEN
                        RAISE EXCEPTION 'AI_HUMAN_REVIEW_NOT_REQUIRED';
                    END IF;
                    IF NOT EXISTS (
                        SELECT 1 FROM request_state_events rse
                        WHERE rse.request_id = parent.id
                          AND rse.from_status = 'AUDITORIA_IA'
                          AND rse.to_status = 'REVISION_HUMANA'
                          AND rse.reason = 'audit_passed_human_review_required'
                    ) THEN
                        RAISE EXCEPTION 'AI_HUMAN_REVIEW_STATE_EVENT_REQUIRED';
                    END IF;
                    RETURN;
                END IF;

                IF parent.status IN ('APROBADA','GENERANDO_DOCUMENTO','LISTA_PARA_ENTREGAR','ENTREGADA','COMPLETADA','CORRECCION_SOLICITADA') THEN
                    IF parent.human_review_required_snapshot THEN
                        IF NOT EXISTS (
                            SELECT 1 FROM approvals a
                            WHERE a.request_id = parent.id
                              AND a.version_id = current_version_id
                              AND a.kind = 'human'
                        ) THEN
                            RAISE EXCEPTION 'HUMAN_APPROVAL_REQUIRED';
                        END IF;
                    ELSE
                        IF NOT EXISTS (
                            SELECT 1 FROM request_state_events rse
                            WHERE rse.request_id = parent.id
                              AND rse.from_status = 'AUDITORIA_IA'
                              AND rse.to_status = 'APROBADA'
                              AND rse.reason = 'audit_passed_ai_approved'
                        ) THEN
                            RAISE EXCEPTION 'AI_APPROVAL_STATE_EVENT_REQUIRED';
                        END IF;
                    END IF;
                END IF;
            END;
            $$;
        SQL);
    }

    public function down(): void
    {
        // Rollback de esquema: las funciones históricas de 4E/5B quedan
        // restauradas al volver a aplicar esas migraciones en migrate:fresh.
        DB::statement('ALTER TABLE reviews DROP CONSTRAINT IF EXISTS reviews_status_check');
        DB::statement("ALTER TABLE reviews ADD CONSTRAINT reviews_status_check CHECK (status IN ('in_progress','approved'))");
    }
};
