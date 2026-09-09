<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('approvals', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('request_id');
            $table->unsignedBigInteger('version_id');
            $table->string('kind', 16);
            $table->unsignedBigInteger('ai_execution_id')->nullable();
            $table->unsignedBigInteger('review_id')->nullable();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->timestampTz('approved_at');
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['version_id', 'kind']);
            $table->index(['request_id', 'approved_at']);
        });

        DB::statement('ALTER TABLE approvals ADD CONSTRAINT approvals_request_fk FOREIGN KEY (request_id) REFERENCES planning_requests(id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE approvals ADD CONSTRAINT approvals_version_fk FOREIGN KEY (version_id) REFERENCES document_versions(id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE approvals ADD CONSTRAINT approvals_ai_execution_fk FOREIGN KEY (ai_execution_id) REFERENCES ai_executions(id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE approvals ADD CONSTRAINT approvals_actor_fk FOREIGN KEY (actor_id) REFERENCES users(id) ON DELETE SET NULL');
        DB::statement("ALTER TABLE approvals ADD CONSTRAINT approvals_kind_check CHECK (kind IN ('ai','human'))");
        DB::statement("ALTER TABLE approvals ADD CONSTRAINT approvals_kind_shape_check CHECK ((kind = 'ai' AND ai_execution_id IS NOT NULL AND review_id IS NULL) OR (kind = 'human' AND ai_execution_id IS NULL AND review_id IS NOT NULL))");

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION approval_history_guard() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP IN ('UPDATE', 'DELETE') THEN
                    RAISE EXCEPTION 'APPROVAL_IMMUTABLE';
                END IF;
                RETURN NEW;
            END;
            $$;
        SQL);
        DB::statement('CREATE TRIGGER approvals_history_trg BEFORE UPDATE OR DELETE ON approvals FOR EACH ROW EXECUTE FUNCTION approval_history_guard()');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION approval_integrity_check(target_id bigint) RETURNS void
            LANGUAGE plpgsql AS $$
            DECLARE
                a approvals;
                version_request_id bigint;
                audit_request_id bigint;
                audit_stage text;
                audit_status text;
                audit_report_value jsonb;
                audit_source_version_id bigint;
            BEGIN
                SELECT * INTO a FROM approvals WHERE id = target_id;
                IF NOT FOUND THEN
                    RETURN;
                END IF;

                SELECT d.request_id INTO version_request_id
                FROM document_versions dv
                JOIN documents d ON d.id = dv.document_id
                WHERE dv.id = a.version_id;

                IF version_request_id IS DISTINCT FROM a.request_id THEN
                    RAISE EXCEPTION 'APPROVAL_VERSION_REQUEST_MISMATCH';
                END IF;

                IF a.kind = 'human' THEN
                    -- Fase 4E solo materializa aprobación AI. La aprobación
                    -- humana requiere reviews/checklist de Fase 5 y no puede
                    -- fabricarse con un review_id sin integridad referencial.
                    RAISE EXCEPTION 'HUMAN_APPROVAL_REVIEW_NOT_READY';
                END IF;

                IF a.kind = 'ai' THEN
                    SELECT ae.request_id, ae.stage, ae.status, ae.audit_report,
                           NULLIF(ae.input_manifest->>'source_version_id', '')::bigint
                    INTO audit_request_id, audit_stage, audit_status, audit_report_value, audit_source_version_id
                    FROM ai_executions ae
                    WHERE ae.id = a.ai_execution_id;

                    IF audit_request_id IS DISTINCT FROM a.request_id
                       OR audit_stage IS DISTINCT FROM 'audit'
                       OR audit_status IS DISTINCT FROM 'succeeded'
                       OR audit_report_value IS NULL
                       OR NOT audit_result_v1_is_valid(audit_report_value)
                       OR (audit_report_value->>'passed')::boolean IS DISTINCT FROM true
                       OR audit_source_version_id IS DISTINCT FROM a.version_id THEN
                        RAISE EXCEPTION 'AI_APPROVAL_AUDIT_MISMATCH';
                    END IF;
                END IF;
            END;
            $$;

            CREATE OR REPLACE FUNCTION approval_integrity_trigger() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                PERFORM approval_integrity_check(NEW.id);
                RETURN NULL;
            END;
            $$;
        SQL);
        DB::statement('CREATE CONSTRAINT TRIGGER approvals_integrity_trg AFTER INSERT ON approvals DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION approval_integrity_trigger()');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION correction_input_manifest_v1_is_valid(manifest jsonb) RETURNS boolean
            LANGUAGE plpgsql IMMUTABLE AS $$
            DECLARE
                key text;
            BEGIN
                IF manifest IS NULL OR jsonb_typeof(manifest) <> 'object' THEN
                    RETURN false;
                END IF;
                IF (manifest - 'schema_version' - 'request_id' - 'input_revision' - 'source_version_id'
                    - 'source_content_hash' - 'source_audit_execution_id' - 'source_audit_report_hash'
                    - 'section_keys' - 'correction_round' - 'correlation_id') <> '{}'::jsonb THEN
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
        DB::statement("ALTER TABLE ai_executions ADD CONSTRAINT ai_executions_correction_manifest_check CHECK (stage <> 'correction' OR correction_input_manifest_v1_is_valid(input_manifest))");

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

                -- La migración 4D es la autoridad para auditoría ausente,
                -- pendiente o inválida. No enmascarar sus códigos canónicos.
                IF audit_id IS NULL OR audit_report_value IS NULL OR NOT audit_result_v1_is_valid(audit_report_value) THEN
                    RETURN;
                END IF;

                IF parent.status = 'CORRECCION_IA' THEN
                    IF (audit_report_value->>'passed')::boolean IS DISTINCT FROM false THEN
                        RAISE EXCEPTION 'AI_CORRECTION_REQUIRES_FAILED_AUDIT';
                    END IF;

                    SELECT ae.id, ae.status
                    INTO correction_id, correction_status
                    FROM ai_executions ae
                    WHERE ae.request_id = parent.id
                      AND ae.stage = 'correction'
                      AND ae.input_revision = parent.input_revision
                      AND ae.input_manifest->>'source_version_id' = current_version_id::text
                      AND ae.input_manifest->>'source_content_hash' = current_content_hash
                      AND ae.input_manifest->>'source_audit_execution_id' = audit_id::text
                    ORDER BY ae.id DESC
                    LIMIT 1;

                    IF correction_id IS NULL OR correction_status NOT IN ('pending','waiting_manual','running') THEN
                        RAISE EXCEPTION 'AI_CORRECTION_EXECUTION_REQUIRED';
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

                    IF NOT EXISTS (
                        SELECT 1 FROM request_state_events rse
                        WHERE rse.request_id = parent.id
                          AND rse.from_status = 'AUDITORIA_IA'
                          AND rse.to_status = 'CORRECCION_IA'
                          AND rse.reason = 'audit_failed_correction_dispatched'
                          AND rse.correlation_id::text = (SELECT input_manifest->>'correlation_id' FROM ai_executions WHERE id = correction_id)
                    ) THEN
                        RAISE EXCEPTION 'AI_CORRECTION_STATE_EVENT_REQUIRED';
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
                        -- Fase 5 reemplazará/extenderá esta frontera al crear
                        -- reviews/checklist y aprobación humana verificable.
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

            CREATE OR REPLACE FUNCTION planning_post_audit_routing_integrity_trigger() RETURNS trigger
            LANGUAGE plpgsql AS $$
            DECLARE
                target_id bigint;
            BEGIN
                IF TG_TABLE_NAME = 'planning_requests' THEN
                    target_id := NEW.id;
                ELSIF TG_TABLE_NAME = 'ai_executions' THEN
                    target_id := NEW.request_id;
                ELSIF TG_TABLE_NAME = 'approvals' THEN
                    target_id := NEW.request_id;
                ELSIF TG_TABLE_NAME = 'documents' THEN
                    target_id := NEW.request_id;
                ELSIF TG_TABLE_NAME = 'outbox_events' AND NEW.type = 'planning.correction.requested' THEN
                    target_id := NEW.aggregate_id;
                END IF;

                IF target_id IS NOT NULL THEN
                    PERFORM planning_post_audit_routing_integrity_check(target_id);
                END IF;
                RETURN NULL;
            END;
            $$;
        SQL);

        DB::statement('CREATE CONSTRAINT TRIGGER planning_post_audit_request_trg AFTER INSERT OR UPDATE ON planning_requests DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION planning_post_audit_routing_integrity_trigger()');
        DB::statement('CREATE CONSTRAINT TRIGGER planning_post_audit_execution_trg AFTER INSERT OR UPDATE ON ai_executions DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION planning_post_audit_routing_integrity_trigger()');
        DB::statement('CREATE CONSTRAINT TRIGGER planning_post_audit_approval_trg AFTER INSERT ON approvals DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION planning_post_audit_routing_integrity_trigger()');
        DB::statement('CREATE CONSTRAINT TRIGGER planning_post_audit_document_trg AFTER INSERT OR UPDATE ON documents DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION planning_post_audit_routing_integrity_trigger()');
        DB::statement('CREATE CONSTRAINT TRIGGER planning_post_audit_outbox_trg AFTER INSERT OR UPDATE ON outbox_events DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION planning_post_audit_routing_integrity_trigger()');
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS planning_post_audit_outbox_trg ON outbox_events');
        DB::statement('DROP TRIGGER IF EXISTS planning_post_audit_document_trg ON documents');
        DB::statement('DROP TRIGGER IF EXISTS planning_post_audit_approval_trg ON approvals');
        DB::statement('DROP TRIGGER IF EXISTS planning_post_audit_execution_trg ON ai_executions');
        DB::statement('DROP TRIGGER IF EXISTS planning_post_audit_request_trg ON planning_requests');
        DB::statement('DROP FUNCTION IF EXISTS planning_post_audit_routing_integrity_trigger()');
        DB::statement('DROP FUNCTION IF EXISTS planning_post_audit_routing_integrity_check(bigint)');

        DB::statement('ALTER TABLE ai_executions DROP CONSTRAINT IF EXISTS ai_executions_correction_manifest_check');
        DB::statement('DROP FUNCTION IF EXISTS correction_input_manifest_v1_is_valid(jsonb)');

        DB::statement('DROP TRIGGER IF EXISTS approvals_integrity_trg ON approvals');
        DB::statement('DROP FUNCTION IF EXISTS approval_integrity_trigger()');
        DB::statement('DROP FUNCTION IF EXISTS approval_integrity_check(bigint)');
        DB::statement('DROP TRIGGER IF EXISTS approvals_history_trg ON approvals');
        DB::statement('DROP FUNCTION IF EXISTS approval_history_guard()');
        Schema::dropIfExists('approvals');
    }
};
