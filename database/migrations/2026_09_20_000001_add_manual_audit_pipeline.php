<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION audit_result_v1_is_valid(report jsonb) RETURNS boolean
            LANGUAGE plpgsql IMMUTABLE AS $$
            DECLARE
                finding jsonb;
                passed_value boolean;
                finding_count integer;
            BEGIN
                IF report IS NULL OR jsonb_typeof(report) <> 'object' THEN
                    RETURN false;
                END IF;
                IF (report - 'schema_version' - 'passed' - 'findings') <> '{}'::jsonb THEN
                    RETURN false;
                END IF;
                IF report->>'schema_version' IS DISTINCT FROM 'audit_result_v1'
                   OR jsonb_typeof(report->'passed') IS DISTINCT FROM 'boolean'
                   OR jsonb_typeof(report->'findings') IS DISTINCT FROM 'array' THEN
                    RETURN false;
                END IF;

                passed_value := (report->>'passed')::boolean;
                finding_count := jsonb_array_length(report->'findings');
                IF (passed_value AND finding_count <> 0)
                   OR (NOT passed_value AND finding_count = 0)
                   OR finding_count > 200 THEN
                    RETURN false;
                END IF;

                FOR finding IN SELECT value FROM jsonb_array_elements(report->'findings') LOOP
                    IF jsonb_typeof(finding) <> 'object'
                       OR (finding - 'code' - 'severity' - 'json_path' - 'explanation' - 'expected_correction') <> '{}'::jsonb
                       OR jsonb_typeof(finding->'code') IS DISTINCT FROM 'string'
                       OR jsonb_typeof(finding->'severity') IS DISTINCT FROM 'string'
                       OR jsonb_typeof(finding->'json_path') IS DISTINCT FROM 'string'
                       OR jsonb_typeof(finding->'explanation') IS DISTINCT FROM 'string'
                       OR jsonb_typeof(finding->'expected_correction') IS DISTINCT FROM 'string' THEN
                        RETURN false;
                    END IF;

                    IF finding->>'code' NOT IN (
                        'SCHEMA', 'CURRICULUM_REFERENCE', 'CURRICULUM_COVERAGE',
                        'PEDAGOGICAL_ALIGNMENT', 'TIME_CONSISTENCY', 'ASSESSMENT_ALIGNMENT',
                        'AGE_APPROPRIATENESS', 'GROUP_CONTEXT', 'MATERIAL_FEASIBILITY',
                        'UNSUPPORTED_ASSUMPTION', 'SAFETY_OR_INCLUSION', 'LANGUAGE_QUALITY'
                    ) OR finding->>'severity' NOT IN ('info', 'low', 'medium', 'high', 'critical') THEN
                        RETURN false;
                    END IF;

                    IF NOT ((finding->>'json_path') = '$' OR left(finding->>'json_path', 1) = '/')
                       OR char_length(finding->>'json_path') > 512
                       OR char_length(btrim(finding->>'explanation')) < 1
                       OR char_length(finding->>'explanation') > 4000
                       OR char_length(btrim(finding->>'expected_correction')) < 1
                       OR char_length(finding->>'expected_correction') > 4000 THEN
                        RETURN false;
                    END IF;
                END LOOP;

                RETURN true;
            EXCEPTION WHEN others THEN
                RETURN false;
            END;
            $$;
        SQL);
        DB::statement("ALTER TABLE ai_executions ADD CONSTRAINT ai_executions_audit_result_shape_check CHECK (audit_report IS NULL OR (stage = 'audit' AND audit_result_v1_is_valid(audit_report)))");

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION ai_execution_result_guard() RETURNS trigger
            LANGUAGE plpgsql AS $$
            DECLARE
                v_version_execution_id bigint;
                v_version_request_id bigint;
                v_version_revision integer;
            BEGIN
                IF TG_OP <> 'UPDATE' THEN
                    RETURN NEW;
                END IF;

                IF OLD.resulting_version_id IS NOT NULL
                   AND OLD.resulting_version_id IS DISTINCT FROM NEW.resulting_version_id THEN
                    RAISE EXCEPTION 'AI_EXECUTION_RESULT_IMMUTABLE';
                END IF;

                IF OLD.status = 'succeeded' AND (
                    OLD.status IS DISTINCT FROM NEW.status
                    OR OLD.finished_at IS DISTINCT FROM NEW.finished_at
                    OR OLD.resulting_version_id IS DISTINCT FROM NEW.resulting_version_id
                    OR OLD.audit_report IS DISTINCT FROM NEW.audit_report
                ) THEN
                    RAISE EXCEPTION 'AI_EXECUTION_SUCCEEDED_IMMUTABLE';
                END IF;

                IF OLD.status = 'succeeded'
                   AND OLD.provider IS NOT NULL
                   AND (OLD.provider IS DISTINCT FROM NEW.provider OR OLD.model IS DISTINCT FROM NEW.model) THEN
                    RAISE EXCEPTION 'AI_EXECUTION_PROVIDER_MODEL_IMMUTABLE';
                END IF;

                IF OLD.status = 'succeeded'
                   AND OLD.actual_cost IS NOT NULL
                   AND (OLD.actual_cost IS DISTINCT FROM NEW.actual_cost OR OLD.cost_currency IS DISTINCT FROM NEW.cost_currency) THEN
                    RAISE EXCEPTION 'AI_EXECUTION_ACTUAL_COST_IMMUTABLE';
                END IF;

                IF NEW.status = 'succeeded' AND NEW.stage IN ('generation', 'correction') THEN
                    IF NEW.resulting_version_id IS NULL OR NEW.finished_at IS NULL THEN
                        RAISE EXCEPTION 'AI_EXECUTION_RESULT_REQUIRED';
                    END IF;

                    SELECT dv.ai_execution_id, d.request_id, dv.input_revision
                    INTO v_version_execution_id, v_version_request_id, v_version_revision
                    FROM document_versions dv
                    JOIN documents d ON d.id = dv.document_id
                    WHERE dv.id = NEW.resulting_version_id;

                    IF v_version_execution_id IS DISTINCT FROM NEW.id
                       OR v_version_request_id IS DISTINCT FROM NEW.request_id
                       OR v_version_revision IS DISTINCT FROM NEW.input_revision THEN
                        RAISE EXCEPTION 'AI_EXECUTION_RESULT_MISMATCH';
                    END IF;
                END IF;

                IF NEW.status = 'succeeded' AND NEW.stage = 'audit' THEN
                    IF NEW.finished_at IS NULL OR NEW.audit_report IS NULL OR NEW.resulting_version_id IS NOT NULL THEN
                        RAISE EXCEPTION 'AI_AUDIT_RESULT_REQUIRED';
                    END IF;
                    IF NOT audit_result_v1_is_valid(NEW.audit_report) THEN
                        RAISE EXCEPTION 'AI_AUDIT_RESULT_INVALID';
                    END IF;
                END IF;

                RETURN NEW;
            END;
            $$;
        SQL);

        // Compatibilidad con solicitudes que alcanzaron AUDITORIA_IA bajo 4C
        // antes de que existiera el evento audit del outbox. No altera la
        // identidad de la ejecución ni fabrica resultados.
        DB::unprepared(<<<'SQL'
            INSERT INTO outbox_events (
                event_key, type, aggregate_id, payload, published_at, attempts,
                available_at, claimed_at, lease_expires_at, last_error_code,
                created_at, updated_at
            )
            SELECT
                'ai-execution:' || ae.id || ':audit-dispatch',
                'planning.audit.requested',
                ae.request_id,
                jsonb_build_object(
                    'request_id', ae.request_id,
                    'ai_execution_id', ae.id,
                    'input_revision', ae.input_revision,
                    'source_version_id', (ae.input_manifest->>'source_version_id')::bigint,
                    'correlation_id', ae.input_manifest->>'correlation_id'
                ),
                NULL, 0, now(), NULL, NULL, NULL, now(), now()
            FROM ai_executions ae
            JOIN planning_requests pr ON pr.id = ae.request_id
            WHERE ae.stage = 'audit'
              AND ae.mode = 'manual'
              AND ae.status IN ('pending', 'waiting_manual')
              AND pr.status = 'AUDITORIA_IA'
              AND ae.input_manifest ? 'source_version_id'
              AND (ae.input_manifest->>'source_version_id') ~ '^[0-9]+$'
              AND ae.input_manifest ? 'correlation_id'
            ON CONFLICT (event_key) DO NOTHING;
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION planning_audit_result_integrity_check(target_id bigint) RETURNS void
            LANGUAGE plpgsql AS $$
            DECLARE
                parent planning_requests;
                current_version_id bigint;
                current_content_hash char(64);
                audit_execution_id bigint;
                audit_status text;
                audit_report_value jsonb;
            BEGIN
                SELECT * INTO parent FROM planning_requests WHERE id = target_id FOR UPDATE;
                IF NOT FOUND OR parent.commercial_authorized_at IS NULL THEN
                    RETURN;
                END IF;

                IF parent.status NOT IN (
                    'AUDITORIA_IA', 'CORRECCION_IA', 'REVISION_HUMANA', 'APROBADA',
                    'GENERANDO_DOCUMENTO', 'LISTA_PARA_ENTREGAR', 'ENTREGADA',
                    'COMPLETADA', 'CORRECCION_SOLICITADA'
                ) THEN
                    RETURN;
                END IF;

                SELECT d.current_version_id, dv.content_hash
                INTO current_version_id, current_content_hash
                FROM documents d
                JOIN document_versions dv ON dv.id = d.current_version_id
                WHERE d.request_id = parent.id;

                IF current_version_id IS NULL OR current_content_hash IS NULL THEN
                    -- La integridad de generación (4C) es la dueña de exigir el
                    -- DocumentVersion fuente; no enmascarar su error canónico.
                    RETURN;
                END IF;

                SELECT ae.id, ae.status, ae.audit_report
                INTO audit_execution_id, audit_status, audit_report_value
                FROM ai_executions ae
                WHERE ae.request_id = parent.id
                  AND ae.stage = 'audit'
                  AND ae.input_revision = parent.input_revision
                  AND ae.input_manifest->>'source_version_id' = current_version_id::text
                  AND ae.input_manifest->>'source_content_hash' = current_content_hash
                ORDER BY ae.id DESC
                LIMIT 1;

                IF audit_execution_id IS NULL THEN
                    RAISE EXCEPTION 'AI_AUDIT_EXECUTION_REQUIRED';
                END IF;

                IF NOT EXISTS (
                    SELECT 1
                    FROM outbox_events oe
                    WHERE oe.event_key = 'ai-execution:' || audit_execution_id || ':audit-dispatch'
                      AND oe.type = 'planning.audit.requested'
                      AND oe.aggregate_id = parent.id
                      AND oe.payload->>'request_id' = parent.id::text
                      AND oe.payload->>'ai_execution_id' = audit_execution_id::text
                      AND oe.payload->>'source_version_id' = current_version_id::text
                ) THEN
                    RAISE EXCEPTION 'AI_AUDIT_OUTBOX_REQUIRED';
                END IF;

                IF audit_status IN ('waiting_manual', 'succeeded') AND NOT EXISTS (
                    SELECT 1 FROM ai_manual_packages WHERE ai_execution_id = audit_execution_id
                ) THEN
                    RAISE EXCEPTION 'AI_AUDIT_MANUAL_PACKAGE_REQUIRED';
                END IF;

                IF parent.status <> 'AUDITORIA_IA' THEN
                    IF audit_status <> 'succeeded' OR audit_report_value IS NULL THEN
                        RAISE EXCEPTION 'AI_AUDIT_SUCCEEDED_REQUIRED';
                    END IF;
                END IF;
            END;
            $$;

            CREATE OR REPLACE FUNCTION planning_audit_result_integrity_trigger() RETURNS trigger
            LANGUAGE plpgsql AS $$
            DECLARE
                target_id bigint;
            BEGIN
                IF TG_TABLE_NAME = 'planning_requests' THEN
                    target_id := NEW.id;
                ELSIF TG_TABLE_NAME = 'ai_executions' THEN
                    target_id := NEW.request_id;
                ELSIF TG_TABLE_NAME = 'documents' THEN
                    target_id := NEW.request_id;
                ELSIF TG_TABLE_NAME = 'outbox_events' AND NEW.type = 'planning.audit.requested' THEN
                    target_id := NEW.aggregate_id;
                END IF;

                IF target_id IS NOT NULL THEN
                    PERFORM planning_audit_result_integrity_check(target_id);
                END IF;
                RETURN NULL;
            END;
            $$;
        SQL);
        DB::statement('CREATE CONSTRAINT TRIGGER planning_audit_result_request_trg AFTER INSERT OR UPDATE ON planning_requests DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION planning_audit_result_integrity_trigger()');
        DB::statement('CREATE CONSTRAINT TRIGGER planning_audit_result_execution_trg AFTER INSERT OR UPDATE ON ai_executions DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION planning_audit_result_integrity_trigger()');
        DB::statement('CREATE CONSTRAINT TRIGGER planning_audit_result_document_trg AFTER INSERT OR UPDATE ON documents DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION planning_audit_result_integrity_trigger()');
        DB::statement('CREATE CONSTRAINT TRIGGER planning_audit_result_outbox_trg AFTER INSERT OR UPDATE ON outbox_events DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION planning_audit_result_integrity_trigger()');
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS planning_audit_result_outbox_trg ON outbox_events');
        DB::statement('DROP TRIGGER IF EXISTS planning_audit_result_document_trg ON documents');
        DB::statement('DROP TRIGGER IF EXISTS planning_audit_result_execution_trg ON ai_executions');
        DB::statement('DROP TRIGGER IF EXISTS planning_audit_result_request_trg ON planning_requests');
        DB::statement('DROP FUNCTION IF EXISTS planning_audit_result_integrity_trigger()');
        DB::statement('DROP FUNCTION IF EXISTS planning_audit_result_integrity_check(bigint)');

        DB::statement('ALTER TABLE ai_executions DROP CONSTRAINT IF EXISTS ai_executions_audit_result_shape_check');
        DB::statement('DROP FUNCTION IF EXISTS audit_result_v1_is_valid(jsonb)');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION ai_execution_result_guard() RETURNS trigger
            LANGUAGE plpgsql AS $$
            DECLARE
                v_version_execution_id bigint;
                v_version_request_id bigint;
                v_version_revision integer;
            BEGIN
                IF TG_OP <> 'UPDATE' THEN
                    RETURN NEW;
                END IF;

                IF OLD.resulting_version_id IS NOT NULL
                   AND OLD.resulting_version_id IS DISTINCT FROM NEW.resulting_version_id THEN
                    RAISE EXCEPTION 'AI_EXECUTION_RESULT_IMMUTABLE';
                END IF;

                IF OLD.status = 'succeeded' AND (
                    OLD.status IS DISTINCT FROM NEW.status
                    OR OLD.finished_at IS DISTINCT FROM NEW.finished_at
                    OR OLD.resulting_version_id IS DISTINCT FROM NEW.resulting_version_id
                ) THEN
                    RAISE EXCEPTION 'AI_EXECUTION_SUCCEEDED_IMMUTABLE';
                END IF;

                IF OLD.status = 'succeeded'
                   AND OLD.provider IS NOT NULL
                   AND (OLD.provider IS DISTINCT FROM NEW.provider OR OLD.model IS DISTINCT FROM NEW.model) THEN
                    RAISE EXCEPTION 'AI_EXECUTION_PROVIDER_MODEL_IMMUTABLE';
                END IF;

                IF OLD.status = 'succeeded'
                   AND OLD.actual_cost IS NOT NULL
                   AND (OLD.actual_cost IS DISTINCT FROM NEW.actual_cost OR OLD.cost_currency IS DISTINCT FROM NEW.cost_currency) THEN
                    RAISE EXCEPTION 'AI_EXECUTION_ACTUAL_COST_IMMUTABLE';
                END IF;

                IF NEW.status = 'succeeded' AND NEW.stage IN ('generation', 'correction') THEN
                    IF NEW.resulting_version_id IS NULL OR NEW.finished_at IS NULL THEN
                        RAISE EXCEPTION 'AI_EXECUTION_RESULT_REQUIRED';
                    END IF;

                    SELECT dv.ai_execution_id, d.request_id, dv.input_revision
                    INTO v_version_execution_id, v_version_request_id, v_version_revision
                    FROM document_versions dv
                    JOIN documents d ON d.id = dv.document_id
                    WHERE dv.id = NEW.resulting_version_id;

                    IF v_version_execution_id IS DISTINCT FROM NEW.id
                       OR v_version_request_id IS DISTINCT FROM NEW.request_id
                       OR v_version_revision IS DISTINCT FROM NEW.input_revision THEN
                        RAISE EXCEPTION 'AI_EXECUTION_RESULT_MISMATCH';
                    END IF;
                END IF;

                RETURN NEW;
            END;
            $$;
        SQL);
    }
};
