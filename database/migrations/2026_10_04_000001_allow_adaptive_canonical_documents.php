<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        $this->installDocumentVersionGuard(adaptive: true);
        $this->installGenerationIntegrityGuard(adaptive: true);
        $this->installCorrectionManifestGuard(adaptive: true);
    }

    public function down(): void
    {
        $this->installCorrectionManifestGuard(adaptive: false);
        $this->installGenerationIntegrityGuard(adaptive: false);
        $this->installDocumentVersionGuard(adaptive: false);
    }

    private function installDocumentVersionGuard(bool $adaptive): void
    {
        $schemas = $adaptive
            ? "'canonical_plan_v1','canonical_adaptive_plan_v1'"
            : "'canonical_plan_v1'";

        DB::unprepared(<<<SQL
            CREATE OR REPLACE FUNCTION document_version_guard() RETURNS trigger
            LANGUAGE plpgsql AS $$
            DECLARE
                v_request_id bigint;
                v_execution_request_id bigint;
                v_execution_revision integer;
                v_execution_stage text;
            BEGIN
                IF TG_OP IN ('UPDATE', 'DELETE') THEN
                    RAISE EXCEPTION 'DOCUMENT_VERSION_IMMUTABLE';
                END IF;

                SELECT request_id INTO v_request_id
                FROM documents
                WHERE id = NEW.document_id
                FOR SHARE;

                IF v_request_id IS NULL THEN
                    RAISE EXCEPTION 'DOCUMENT_VERSION_DOCUMENT_MISSING';
                END IF;

                IF NEW.content->>'schema_version' NOT IN ({$schemas})
                   OR NULLIF(NEW.content#>>'{source,planning_request_id}', '')::bigint IS DISTINCT FROM v_request_id
                   OR NULLIF(NEW.content#>>'{source,input_revision}', '')::integer IS DISTINCT FROM NEW.input_revision THEN
                    RAISE EXCEPTION 'DOCUMENT_VERSION_CANONICAL_SOURCE_MISMATCH';
                END IF;

                IF NEW.ai_execution_id IS NOT NULL THEN
                    SELECT request_id, input_revision, stage
                    INTO v_execution_request_id, v_execution_revision, v_execution_stage
                    FROM ai_executions
                    WHERE id = NEW.ai_execution_id
                    FOR SHARE;

                    IF v_execution_request_id IS NULL
                       OR v_execution_request_id IS DISTINCT FROM v_request_id
                       OR v_execution_revision IS DISTINCT FROM NEW.input_revision
                       OR v_execution_stage NOT IN ('generation', 'correction')
                       OR NEW.source_payload_hash IS NULL THEN
                        RAISE EXCEPTION 'DOCUMENT_VERSION_EXECUTION_MISMATCH';
                    END IF;
                END IF;

                RETURN NEW;
            END;
            $$;
        SQL);
    }

    private function installGenerationIntegrityGuard(bool $adaptive): void
    {
        $schemas = $adaptive
            ? "'canonical_plan_v1','canonical_adaptive_plan_v1'"
            : "'canonical_plan_v1'";

        DB::unprepared(<<<SQL
            CREATE OR REPLACE FUNCTION planning_generation_result_integrity_check(target_id bigint) RETURNS void
            LANGUAGE plpgsql AS $$
            DECLARE
                parent planning_requests;
                generation_execution_id bigint;
                generation_version_id bigint;
                generation_correlation_id text;
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

                SELECT id, resulting_version_id, input_manifest->>'correlation_id'
                INTO generation_execution_id, generation_version_id, generation_correlation_id
                FROM ai_executions
                WHERE request_id = parent.id
                  AND stage = 'generation'
                  AND input_revision = parent.input_revision
                  AND status = 'succeeded'
                  AND resulting_version_id IS NOT NULL
                ORDER BY id DESC
                LIMIT 1;

                IF generation_execution_id IS NULL OR generation_version_id IS NULL OR generation_correlation_id IS NULL THEN
                    RAISE EXCEPTION 'AI_GENERATION_RESULT_REQUIRED';
                END IF;

                IF NOT EXISTS (
                    SELECT 1
                    FROM document_versions dv
                    JOIN documents d ON d.id = dv.document_id
                    WHERE dv.id = generation_version_id
                      AND dv.ai_execution_id = generation_execution_id
                      AND dv.input_revision = parent.input_revision
                      AND dv.status IN ('validated', 'approved')
                      AND d.request_id = parent.id
                      AND d.current_version_id IS NOT NULL
                      AND dv.content->>'schema_version' IN ({$schemas})
                      AND dv.content#>>'{source,planning_request_id}' = parent.id::text
                      AND dv.content#>>'{source,input_revision}' = parent.input_revision::text
                ) THEN
                    RAISE EXCEPTION 'AI_GENERATION_DOCUMENT_VERSION_REQUIRED';
                END IF;

                IF NOT EXISTS (
                    SELECT 1 FROM request_state_events
                    WHERE request_id = parent.id
                      AND from_status = 'GENERACION_IA'
                      AND to_status = 'AUDITORIA_IA'
                      AND reason = 'generation_result_imported'
                      AND correlation_id::text = generation_correlation_id
                ) THEN
                    RAISE EXCEPTION 'AI_GENERATION_RESULT_STATE_EVENT_REQUIRED';
                END IF;

                IF NOT EXISTS (
                    SELECT 1 FROM ai_executions
                    WHERE request_id = parent.id
                      AND stage = 'audit'
                      AND input_revision = parent.input_revision
                      AND input_manifest->>'source_version_id' = generation_version_id::text
                      AND input_manifest->>'source_content_hash' = (
                          SELECT content_hash FROM document_versions WHERE id = generation_version_id
                      )
                      AND input_manifest->>'correlation_id' = generation_correlation_id
                ) THEN
                    RAISE EXCEPTION 'AI_AUDIT_EXECUTION_REQUIRED';
                END IF;
            END;
            $$;
        SQL);
    }

    private function installCorrectionManifestGuard(bool $adaptive): void
    {
        $sectionKeys = $adaptive
            ? "'planning','pedagogical_design','sessions','assessment_plan','resources','adaptation_notes','template_fields','custom'"
            : "'planning','pedagogical_design','sessions','assessment_plan','resources','adaptation_notes'";

        DB::unprepared(<<<SQL
            CREATE OR REPLACE FUNCTION correction_input_manifest_v1_is_valid(manifest jsonb) RETURNS boolean
            LANGUAGE plpgsql IMMUTABLE AS $$
            DECLARE key text; source_kind text;
            BEGIN
                IF manifest IS NULL OR jsonb_typeof(manifest) <> 'object' THEN RETURN false; END IF;
                IF (manifest - 'schema_version' - 'request_id' - 'input_revision' - 'source_kind'
                    - 'source_version_id' - 'source_content_hash' - 'source_audit_execution_id'
                    - 'source_audit_report_hash' - 'source_review_id' - 'source_review_payload_hash'
                    - 'source_correction_request_id' - 'source_correction_payload_hash'
                    - 'section_keys' - 'correction_round' - 'correlation_id') <> '{}'::jsonb THEN RETURN false; END IF;
                source_kind := COALESCE(NULLIF(manifest->>'source_kind', ''), 'audit');
                IF source_kind NOT IN ('audit','human_review','client') THEN RETURN false; END IF;
                IF NULLIF(manifest->>'schema_version', '')::integer IS DISTINCT FROM 1
                   OR COALESCE(NULLIF(manifest->>'request_id', '')::bigint, 0) < 1
                   OR NULLIF(manifest->>'input_revision', '')::integer IS NULL
                   OR COALESCE(NULLIF(manifest->>'source_version_id', '')::bigint, 0) < 1
                   OR COALESCE(NULLIF(manifest->>'source_audit_execution_id', '')::bigint, 0) < 1
                   OR COALESCE(NULLIF(manifest->>'correction_round', '')::integer, 0) < 1
                   OR manifest->>'source_content_hash' !~ '^[0-9a-f]{64}$'
                   OR manifest->>'source_audit_report_hash' !~ '^[0-9a-f]{64}$'
                   OR jsonb_typeof(manifest->'section_keys') IS DISTINCT FROM 'array'
                   OR jsonb_array_length(manifest->'section_keys') < 1
                   OR manifest->>'correlation_id' !~* '^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$' THEN RETURN false; END IF;

                IF source_kind = 'audit' THEN
                    IF manifest ? 'source_review_id' OR manifest ? 'source_review_payload_hash'
                       OR manifest ? 'source_correction_request_id' OR manifest ? 'source_correction_payload_hash' THEN RETURN false; END IF;
                ELSIF source_kind = 'human_review' THEN
                    IF COALESCE(NULLIF(manifest->>'source_review_id', '')::bigint, 0) < 1
                       OR manifest->>'source_review_payload_hash' !~ '^[0-9a-f]{64}$'
                       OR manifest ? 'source_correction_request_id' OR manifest ? 'source_correction_payload_hash' THEN RETURN false; END IF;
                ELSE
                    IF COALESCE(NULLIF(manifest->>'source_correction_request_id', '')::bigint, 0) < 1
                       OR manifest->>'source_correction_payload_hash' !~ '^[0-9a-f]{64}$'
                       OR manifest ? 'source_review_id' OR manifest ? 'source_review_payload_hash' THEN RETURN false; END IF;
                END IF;

                FOR key IN SELECT jsonb_array_elements_text(manifest->'section_keys') LOOP
                    IF key NOT IN ({$sectionKeys}) THEN RETURN false; END IF;
                END LOOP;
                RETURN true;
            EXCEPTION WHEN others THEN RETURN false;
            END;
            $$;
        SQL);
    }
};
