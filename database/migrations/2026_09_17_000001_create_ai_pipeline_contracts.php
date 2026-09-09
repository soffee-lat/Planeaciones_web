<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('prompt_templates', function (Blueprint $table) {
            $table->id();
            $table->string('key', 128)->unique();
            $table->string('category', 32);
            $table->string('name', 255);
            // FK circular agregada después de crear prompt_versions.
            $table->unsignedBigInteger('active_version_id')->nullable();
            $table->timestampsTz();
        });

        DB::statement("ALTER TABLE prompt_templates ADD CONSTRAINT prompt_templates_category_check CHECK (category IN ('generation','audit','correction','document_analysis','format_adaptation'))");

        Schema::create('prompt_versions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('template_id');
            $table->unsignedInteger('number');
            $table->text('body');
            $table->jsonb('allowed_variables');
            $table->jsonb('output_schema');
            $table->string('schema_version', 64);
            $table->timestampTz('published_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->char('checksum', 64)->nullable();
            $table->timestampsTz();

            $table->unique(['template_id', 'number']);
            $table->unique(['id', 'template_id'], 'prompt_versions_id_template_unique');
            $table->index(['template_id', 'published_at']);
        });

        DB::statement('ALTER TABLE prompt_versions ADD CONSTRAINT prompt_versions_template_fk FOREIGN KEY (template_id) REFERENCES prompt_templates(id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE prompt_versions ADD CONSTRAINT prompt_versions_created_by_fk FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL');
        DB::statement("ALTER TABLE prompt_versions ADD CONSTRAINT prompt_versions_number_check CHECK (number > 0)");
        DB::statement("ALTER TABLE prompt_versions ADD CONSTRAINT prompt_versions_schema_version_check CHECK (length(trim(schema_version)) > 0)");
        DB::statement("ALTER TABLE prompt_versions ADD CONSTRAINT prompt_versions_body_check CHECK (length(trim(body)) > 0)");
        DB::statement("ALTER TABLE prompt_versions ADD CONSTRAINT prompt_versions_allowed_variables_json_check CHECK (jsonb_typeof(allowed_variables) = 'array')");
        DB::statement("ALTER TABLE prompt_versions ADD CONSTRAINT prompt_versions_output_schema_json_check CHECK (jsonb_typeof(output_schema) = 'object')");
        DB::statement("ALTER TABLE prompt_versions ADD CONSTRAINT prompt_versions_published_checksum_check CHECK (published_at IS NULL OR (checksum IS NOT NULL AND checksum ~ '^[0-9a-f]{64}$'))");
        DB::statement("ALTER TABLE prompt_versions ALTER COLUMN allowed_variables SET DEFAULT '[]'::jsonb");

        DB::statement('ALTER TABLE prompt_templates ADD CONSTRAINT prompt_templates_active_version_fk FOREIGN KEY (active_version_id) REFERENCES prompt_versions(id) ON DELETE SET NULL');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION prompt_version_immutability_guard() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'DELETE' AND OLD.published_at IS NOT NULL THEN
                    RAISE EXCEPTION 'PROMPT_VERSION_PUBLISHED_IMMUTABLE';
                END IF;

                IF TG_OP = 'UPDATE' AND OLD.published_at IS NOT NULL THEN
                    RAISE EXCEPTION 'PROMPT_VERSION_PUBLISHED_IMMUTABLE';
                END IF;

                IF TG_OP = 'DELETE' THEN
                    RETURN OLD;
                END IF;

                RETURN NEW;
            END;
            $$;
        SQL);
        DB::statement('CREATE TRIGGER prompt_versions_immutability_trg BEFORE UPDATE OR DELETE ON prompt_versions FOR EACH ROW EXECUTE FUNCTION prompt_version_immutability_guard()');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION prompt_template_active_version_guard() RETURNS trigger
            LANGUAGE plpgsql AS $$
            DECLARE
                v_template_id bigint;
                v_published_at timestamptz;
            BEGIN
                IF NEW.active_version_id IS NULL THEN
                    RETURN NEW;
                END IF;

                SELECT template_id, published_at INTO v_template_id, v_published_at
                FROM prompt_versions
                WHERE id = NEW.active_version_id
                FOR SHARE;

                IF v_template_id IS NULL THEN
                    RAISE EXCEPTION 'PROMPT_ACTIVE_VERSION_NOT_FOUND';
                END IF;
                IF v_template_id <> NEW.id THEN
                    RAISE EXCEPTION 'PROMPT_ACTIVE_VERSION_TEMPLATE_MISMATCH';
                END IF;
                IF v_published_at IS NULL THEN
                    RAISE EXCEPTION 'PROMPT_ACTIVE_VERSION_NOT_PUBLISHED';
                END IF;

                RETURN NEW;
            END;
            $$;
        SQL);
        DB::statement('CREATE TRIGGER prompt_templates_active_version_trg BEFORE INSERT OR UPDATE OF active_version_id ON prompt_templates FOR EACH ROW EXECUTE FUNCTION prompt_template_active_version_guard()');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION prompt_template_identity_guard() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                IF (OLD.key IS DISTINCT FROM NEW.key OR OLD.category IS DISTINCT FROM NEW.category)
                   AND EXISTS (SELECT 1 FROM prompt_versions WHERE template_id = OLD.id) THEN
                    RAISE EXCEPTION 'PROMPT_TEMPLATE_IDENTITY_IMMUTABLE';
                END IF;
                RETURN NEW;
            END;
            $$;
        SQL);
        DB::statement('CREATE TRIGGER prompt_templates_identity_trg BEFORE UPDATE OF key, category ON prompt_templates FOR EACH ROW EXECUTE FUNCTION prompt_template_identity_guard()');

        Schema::create('ai_executions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('request_id')->nullable();
            // format_versions/files/document_versions se crearán en fases posteriores.
            $table->unsignedBigInteger('format_version_id')->nullable();
            $table->string('stage', 32);
            $table->string('mode', 16);
            $table->string('provider', 128)->nullable();
            $table->string('model', 128)->nullable();
            $table->unsignedBigInteger('prompt_version_id');
            $table->unsignedInteger('input_revision')->nullable();
            $table->jsonb('input_manifest');
            $table->char('rendered_prompt_hash', 64)->nullable();
            $table->unsignedBigInteger('private_payload_file_id')->nullable();
            $table->string('operation_key', 255)->unique();
            $table->string('status', 32);
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->unsignedBigInteger('duration_ms')->nullable();
            $table->string('error_code', 128)->nullable();
            $table->text('sanitized_error')->nullable();
            $table->decimal('estimated_cost', 18, 8)->nullable();
            $table->decimal('actual_cost', 18, 8)->nullable();
            $table->string('cost_currency', 8)->nullable();
            $table->unsignedBigInteger('resulting_version_id')->nullable();
            $table->jsonb('audit_report')->nullable();
            $table->timestampsTz();

            $table->index(['status', 'started_at']);
            $table->index(['request_id', 'stage']);
        });

        DB::statement('ALTER TABLE ai_executions ADD CONSTRAINT ai_executions_request_fk FOREIGN KEY (request_id) REFERENCES planning_requests(id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE ai_executions ADD CONSTRAINT ai_executions_prompt_version_fk FOREIGN KEY (prompt_version_id) REFERENCES prompt_versions(id) ON DELETE RESTRICT');
        DB::statement("ALTER TABLE ai_executions ADD CONSTRAINT ai_executions_stage_check CHECK (stage IN ('generation','audit','correction','document_analysis','format_adaptation'))");
        DB::statement("ALTER TABLE ai_executions ADD CONSTRAINT ai_executions_mode_check CHECK (mode IN ('manual','api'))");
        DB::statement("ALTER TABLE ai_executions ADD CONSTRAINT ai_executions_status_check CHECK (status IN ('pending','waiting_manual','running','succeeded','failed','uncertain'))");
        DB::statement('ALTER TABLE ai_executions ADD CONSTRAINT ai_executions_subject_check CHECK (request_id IS NOT NULL OR format_version_id IS NOT NULL)');
        DB::statement('ALTER TABLE ai_executions ADD CONSTRAINT ai_executions_duration_check CHECK (duration_ms IS NULL OR duration_ms >= 0)');
        DB::statement('ALTER TABLE ai_executions ADD CONSTRAINT ai_executions_estimated_cost_check CHECK (estimated_cost IS NULL OR estimated_cost >= 0)');
        DB::statement('ALTER TABLE ai_executions ADD CONSTRAINT ai_executions_actual_cost_check CHECK (actual_cost IS NULL OR actual_cost >= 0)');
        DB::statement("ALTER TABLE ai_executions ADD CONSTRAINT ai_executions_input_manifest_json_check CHECK (jsonb_typeof(input_manifest) = 'object')");
        DB::statement("ALTER TABLE ai_executions ADD CONSTRAINT ai_executions_audit_report_json_check CHECK (audit_report IS NULL OR jsonb_typeof(audit_report) = 'object')");
        DB::statement("ALTER TABLE ai_executions ADD CONSTRAINT ai_executions_rendered_hash_check CHECK (rendered_prompt_hash IS NULL OR rendered_prompt_hash ~ '^[0-9a-f]{64}$')");
        DB::statement("ALTER TABLE ai_executions ADD CONSTRAINT ai_executions_operation_key_check CHECK (length(trim(operation_key)) > 0)");
        DB::statement("ALTER TABLE ai_executions ALTER COLUMN input_manifest SET DEFAULT '{}'::jsonb");
        DB::statement('ALTER TABLE ai_executions ADD CONSTRAINT ai_executions_request_revision_check CHECK (request_id IS NULL OR input_revision IS NOT NULL)');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION ai_execution_prompt_published_guard() RETURNS trigger
            LANGUAGE plpgsql AS $$
            DECLARE
                v_published_at timestamptz;
            BEGIN
                SELECT published_at INTO v_published_at
                FROM prompt_versions
                WHERE id = NEW.prompt_version_id
                FOR SHARE;

                IF v_published_at IS NULL THEN
                    RAISE EXCEPTION 'AI_EXECUTION_PROMPT_VERSION_NOT_PUBLISHED';
                END IF;

                RETURN NEW;
            END;
            $$;
        SQL);
        DB::statement('CREATE TRIGGER ai_executions_prompt_published_trg BEFORE INSERT OR UPDATE OF prompt_version_id ON ai_executions FOR EACH ROW EXECUTE FUNCTION ai_execution_prompt_published_guard()');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION ai_execution_identity_guard() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'AI_EXECUTION_HISTORY_IMMUTABLE';
                END IF;

                IF OLD.request_id IS DISTINCT FROM NEW.request_id
                   OR OLD.format_version_id IS DISTINCT FROM NEW.format_version_id
                   OR OLD.stage IS DISTINCT FROM NEW.stage
                   OR OLD.mode IS DISTINCT FROM NEW.mode
                   OR OLD.prompt_version_id IS DISTINCT FROM NEW.prompt_version_id
                   OR OLD.input_revision IS DISTINCT FROM NEW.input_revision
                   OR OLD.input_manifest IS DISTINCT FROM NEW.input_manifest
                   OR OLD.operation_key IS DISTINCT FROM NEW.operation_key THEN
                    RAISE EXCEPTION 'AI_EXECUTION_IDENTITY_IMMUTABLE';
                END IF;

                RETURN NEW;
            END;
            $$;
        SQL);
        DB::statement('CREATE TRIGGER ai_executions_identity_trg BEFORE UPDATE OR DELETE ON ai_executions FOR EACH ROW EXECUTE FUNCTION ai_execution_identity_guard()');
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS ai_executions_identity_trg ON ai_executions');
        DB::statement('DROP FUNCTION IF EXISTS ai_execution_identity_guard()');
        DB::statement('DROP TRIGGER IF EXISTS ai_executions_prompt_published_trg ON ai_executions');
        DB::statement('DROP FUNCTION IF EXISTS ai_execution_prompt_published_guard()');
        Schema::dropIfExists('ai_executions');

        DB::statement('DROP TRIGGER IF EXISTS prompt_templates_identity_trg ON prompt_templates');
        DB::statement('DROP FUNCTION IF EXISTS prompt_template_identity_guard()');
        DB::statement('DROP TRIGGER IF EXISTS prompt_templates_active_version_trg ON prompt_templates');
        DB::statement('DROP FUNCTION IF EXISTS prompt_template_active_version_guard()');
        DB::statement('ALTER TABLE prompt_templates DROP CONSTRAINT IF EXISTS prompt_templates_active_version_fk');

        DB::statement('DROP TRIGGER IF EXISTS prompt_versions_immutability_trg ON prompt_versions');
        DB::statement('DROP FUNCTION IF EXISTS prompt_version_immutability_guard()');

        Schema::dropIfExists('prompt_versions');
        Schema::dropIfExists('prompt_templates');
    }
};
