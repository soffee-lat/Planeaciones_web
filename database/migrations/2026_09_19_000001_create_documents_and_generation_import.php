<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('request_id')->unique();
            $table->unsignedBigInteger('owner_id');
            $table->string('title', 255);
            // FK circular agregada después de crear document_versions.
            $table->unsignedBigInteger('current_version_id')->nullable();
            $table->timestampsTz();

            $table->unique(['id', 'request_id'], 'documents_id_request_unique');
        });

        DB::statement('ALTER TABLE documents ADD CONSTRAINT documents_request_owner_fk FOREIGN KEY (request_id, owner_id) REFERENCES planning_requests(id, owner_id) ON DELETE RESTRICT');
        DB::statement("ALTER TABLE documents ADD CONSTRAINT documents_title_check CHECK (length(trim(title)) > 0)");

        Schema::create('document_versions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('document_id');
            $table->unsignedInteger('number');
            $table->unsignedBigInteger('parent_version_id')->nullable();
            $table->unsignedInteger('input_revision');
            $table->jsonb('content');
            $table->char('content_hash', 64);
            // Hash del GeneratedPlanDraftV1 importado. Conserva idempotencia sin
            // duplicar el payload crudo junto al canonical validado.
            $table->char('source_payload_hash', 64)->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('ai_execution_id')->nullable()->unique();
            $table->string('status', 24);
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['document_id', 'number']);
            $table->unique(['id', 'document_id'], 'document_versions_id_document_unique');
            $table->index(['document_id', 'status']);
        });

        DB::statement('ALTER TABLE document_versions ADD CONSTRAINT document_versions_document_fk FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE document_versions ADD CONSTRAINT document_versions_created_by_fk FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL');
        DB::statement('ALTER TABLE document_versions ADD CONSTRAINT document_versions_ai_execution_fk FOREIGN KEY (ai_execution_id) REFERENCES ai_executions(id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE document_versions ADD CONSTRAINT document_versions_parent_same_document_fk FOREIGN KEY (parent_version_id, document_id) REFERENCES document_versions(id, document_id) DEFERRABLE INITIALLY DEFERRED');
        DB::statement('ALTER TABLE document_versions ADD CONSTRAINT document_versions_number_check CHECK (number > 0)');
        DB::statement("ALTER TABLE document_versions ADD CONSTRAINT document_versions_content_object_check CHECK (jsonb_typeof(content) = 'object')");
        DB::statement("ALTER TABLE document_versions ADD CONSTRAINT document_versions_content_hash_check CHECK (content_hash ~ '^[0-9a-f]{64}$')");
        DB::statement("ALTER TABLE document_versions ADD CONSTRAINT document_versions_source_hash_check CHECK (source_payload_hash IS NULL OR source_payload_hash ~ '^[0-9a-f]{64}$')");
        DB::statement("ALTER TABLE document_versions ADD CONSTRAINT document_versions_status_check CHECK (status IN ('draft','validated','approved'))");

        DB::statement('ALTER TABLE documents ADD CONSTRAINT documents_current_version_same_document_fk FOREIGN KEY (current_version_id, id) REFERENCES document_versions(id, document_id) DEFERRABLE INITIALLY DEFERRED');
        DB::statement('ALTER TABLE ai_executions ADD CONSTRAINT ai_executions_resulting_version_fk FOREIGN KEY (resulting_version_id) REFERENCES document_versions(id) ON DELETE RESTRICT');
        DB::statement("ALTER TABLE ai_executions ADD CONSTRAINT ai_executions_actual_cost_currency_check CHECK ((actual_cost IS NULL AND cost_currency IS NULL) OR (actual_cost IS NOT NULL AND cost_currency IS NOT NULL AND cost_currency ~ '^[A-Z]{3}$'))");
        DB::statement("ALTER TABLE ai_executions ADD CONSTRAINT ai_executions_provider_model_pair_check CHECK ((provider IS NULL AND model IS NULL) OR (provider IS NOT NULL AND model IS NOT NULL))");
        DB::statement("ALTER TABLE ai_executions ADD CONSTRAINT ai_executions_generation_result_check CHECK (status <> 'succeeded' OR stage NOT IN ('generation','correction') OR (resulting_version_id IS NOT NULL AND finished_at IS NOT NULL))");

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION document_history_guard() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'DOCUMENT_HISTORY_IMMUTABLE';
                END IF;

                IF OLD.request_id IS DISTINCT FROM NEW.request_id
                   OR OLD.owner_id IS DISTINCT FROM NEW.owner_id THEN
                    RAISE EXCEPTION 'DOCUMENT_IDENTITY_IMMUTABLE';
                END IF;

                IF OLD.current_version_id IS NOT NULL
                   AND OLD.current_version_id IS DISTINCT FROM NEW.current_version_id
                   AND (
                       NEW.current_version_id IS NULL
                       OR (SELECT number FROM document_versions WHERE id = NEW.current_version_id AND document_id = NEW.id)
                          <= (SELECT number FROM document_versions WHERE id = OLD.current_version_id AND document_id = OLD.id)
                   ) THEN
                    RAISE EXCEPTION 'DOCUMENT_CURRENT_VERSION_MUST_ADVANCE';
                END IF;

                RETURN NEW;
            END;
            $$;
        SQL);
        DB::statement('CREATE TRIGGER documents_history_trg BEFORE UPDATE OR DELETE ON documents FOR EACH ROW EXECUTE FUNCTION document_history_guard()');

        DB::unprepared(<<<'SQL'
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

                IF NEW.content->>'schema_version' <> 'canonical_plan_v1'
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
        DB::statement('CREATE TRIGGER document_versions_guard_trg BEFORE INSERT OR UPDATE OR DELETE ON document_versions FOR EACH ROW EXECUTE FUNCTION document_version_guard()');

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
        DB::statement('CREATE TRIGGER ai_executions_result_guard_trg BEFORE UPDATE ON ai_executions FOR EACH ROW EXECUTE FUNCTION ai_execution_result_guard()');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION document_execution_integrity_check(target_version_id bigint) RETURNS void
            LANGUAGE plpgsql AS $$
            DECLARE
                v_version document_versions;
                v_execution ai_executions;
            BEGIN
                SELECT * INTO v_version FROM document_versions WHERE id = target_version_id;
                IF NOT FOUND OR v_version.ai_execution_id IS NULL THEN
                    RETURN;
                END IF;

                SELECT * INTO v_execution FROM ai_executions WHERE id = v_version.ai_execution_id;
                IF NOT FOUND
                   OR v_execution.resulting_version_id IS DISTINCT FROM v_version.id
                   OR v_execution.status <> 'succeeded' THEN
                    RAISE EXCEPTION 'DOCUMENT_VERSION_EXECUTION_RESULT_INCOMPLETE';
                END IF;
            END;
            $$;

            CREATE OR REPLACE FUNCTION document_execution_integrity_trigger() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_TABLE_NAME = 'document_versions' THEN
                    PERFORM document_execution_integrity_check(NEW.id);
                ELSIF NEW.resulting_version_id IS NOT NULL THEN
                    PERFORM document_execution_integrity_check(NEW.resulting_version_id);
                END IF;
                RETURN NULL;
            END;
            $$;
        SQL);
        DB::statement('CREATE CONSTRAINT TRIGGER document_versions_execution_integrity_trg AFTER INSERT OR UPDATE ON document_versions DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION document_execution_integrity_trigger()');
        DB::statement('CREATE CONSTRAINT TRIGGER ai_executions_document_integrity_trg AFTER INSERT OR UPDATE ON ai_executions DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION document_execution_integrity_trigger()');

        DB::unprepared(<<<'SQL'
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
                      AND dv.content->>'schema_version' = 'canonical_plan_v1'
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

            CREATE OR REPLACE FUNCTION planning_generation_result_integrity_trigger() RETURNS trigger
            LANGUAGE plpgsql AS $$
            DECLARE
                target_id bigint;
            BEGIN
                IF TG_TABLE_NAME = 'planning_requests' THEN
                    target_id := NEW.id;
                ELSIF TG_TABLE_NAME = 'documents' THEN
                    target_id := NEW.request_id;
                ELSIF TG_TABLE_NAME = 'document_versions' THEN
                    SELECT request_id INTO target_id FROM documents WHERE id = NEW.document_id;
                ELSIF TG_TABLE_NAME = 'ai_executions' THEN
                    target_id := NEW.request_id;
                END IF;

                IF target_id IS NOT NULL THEN
                    PERFORM planning_generation_result_integrity_check(target_id);
                END IF;
                RETURN NULL;
            END;
            $$;
        SQL);
        DB::statement('CREATE CONSTRAINT TRIGGER planning_generation_result_request_trg AFTER INSERT OR UPDATE ON planning_requests DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION planning_generation_result_integrity_trigger()');
        DB::statement('CREATE CONSTRAINT TRIGGER planning_generation_result_documents_trg AFTER INSERT OR UPDATE ON documents DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION planning_generation_result_integrity_trigger()');
        DB::statement('CREATE CONSTRAINT TRIGGER planning_generation_result_versions_trg AFTER INSERT OR UPDATE ON document_versions DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION planning_generation_result_integrity_trigger()');
        DB::statement('CREATE CONSTRAINT TRIGGER planning_generation_result_executions_trg AFTER INSERT OR UPDATE ON ai_executions DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION planning_generation_result_integrity_trigger()');
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS planning_generation_result_executions_trg ON ai_executions');
        DB::statement('DROP TRIGGER IF EXISTS planning_generation_result_versions_trg ON document_versions');
        DB::statement('DROP TRIGGER IF EXISTS planning_generation_result_documents_trg ON documents');
        DB::statement('DROP TRIGGER IF EXISTS planning_generation_result_request_trg ON planning_requests');
        DB::statement('DROP FUNCTION IF EXISTS planning_generation_result_integrity_trigger()');
        DB::statement('DROP FUNCTION IF EXISTS planning_generation_result_integrity_check(bigint)');

        DB::statement('DROP TRIGGER IF EXISTS ai_executions_document_integrity_trg ON ai_executions');
        DB::statement('DROP TRIGGER IF EXISTS document_versions_execution_integrity_trg ON document_versions');
        DB::statement('DROP FUNCTION IF EXISTS document_execution_integrity_trigger()');
        DB::statement('DROP FUNCTION IF EXISTS document_execution_integrity_check(bigint)');

        DB::statement('DROP TRIGGER IF EXISTS ai_executions_result_guard_trg ON ai_executions');
        DB::statement('DROP FUNCTION IF EXISTS ai_execution_result_guard()');
        DB::statement('DROP TRIGGER IF EXISTS document_versions_guard_trg ON document_versions');
        DB::statement('DROP FUNCTION IF EXISTS document_version_guard()');
        DB::statement('DROP TRIGGER IF EXISTS documents_history_trg ON documents');
        DB::statement('DROP FUNCTION IF EXISTS document_history_guard()');

        DB::statement('ALTER TABLE ai_executions DROP CONSTRAINT IF EXISTS ai_executions_generation_result_check');
        DB::statement('ALTER TABLE ai_executions DROP CONSTRAINT IF EXISTS ai_executions_provider_model_pair_check');
        DB::statement('ALTER TABLE ai_executions DROP CONSTRAINT IF EXISTS ai_executions_actual_cost_currency_check');
        DB::statement('ALTER TABLE ai_executions DROP CONSTRAINT IF EXISTS ai_executions_resulting_version_fk');
        DB::statement('ALTER TABLE documents DROP CONSTRAINT IF EXISTS documents_current_version_same_document_fk');

        Schema::dropIfExists('document_versions');
        Schema::dropIfExists('documents');
    }
};
