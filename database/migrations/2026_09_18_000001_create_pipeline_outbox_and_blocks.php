<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION request_state_event_immutability_guard() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'REQUEST_STATE_EVENT_IMMUTABLE';
            END;
            $$;
        SQL);
        DB::statement('CREATE TRIGGER request_state_events_immutability_trg BEFORE UPDATE OR DELETE ON request_state_events FOR EACH ROW EXECUTE FUNCTION request_state_event_immutability_guard()');

        Schema::create('request_blocks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('request_id');
            $table->string('code', 64);
            $table->string('stage', 32)->nullable();
            $table->jsonb('details');
            $table->timestampTz('opened_at')->useCurrent();
            $table->timestampTz('resolved_at')->nullable();
            $table->unsignedBigInteger('resolved_by')->nullable();
            $table->uuid('correlation_id')->nullable();
            $table->timestampsTz();

            $table->index(['request_id', 'resolved_at']);
            $table->index(['resolved_at', 'code']);
        });

        DB::statement('ALTER TABLE request_blocks ADD CONSTRAINT request_blocks_request_fk FOREIGN KEY (request_id) REFERENCES planning_requests(id) ON DELETE CASCADE');
        DB::statement('ALTER TABLE request_blocks ADD CONSTRAINT request_blocks_resolved_by_fk FOREIGN KEY (resolved_by) REFERENCES users(id) ON DELETE SET NULL');
        DB::statement("ALTER TABLE request_blocks ADD CONSTRAINT request_blocks_code_check CHECK (length(trim(code)) > 0)");
        DB::statement("ALTER TABLE request_blocks ADD CONSTRAINT request_blocks_stage_check CHECK (stage IS NULL OR length(trim(stage)) > 0)");
        DB::statement("ALTER TABLE request_blocks ADD CONSTRAINT request_blocks_details_json_check CHECK (jsonb_typeof(details) = 'object')");
        DB::statement("ALTER TABLE request_blocks ALTER COLUMN details SET DEFAULT '{}'::jsonb");
        DB::statement('ALTER TABLE request_blocks ADD CONSTRAINT request_blocks_resolution_check CHECK (resolved_at IS NULL OR resolved_at >= opened_at)');
        DB::statement("CREATE UNIQUE INDEX request_blocks_one_open_code_stage_uniq ON request_blocks (request_id, code, (COALESCE(stage, ''))) WHERE resolved_at IS NULL");

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION request_block_history_guard() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'REQUEST_BLOCK_HISTORY_IMMUTABLE';
                END IF;

                IF OLD.request_id IS DISTINCT FROM NEW.request_id
                   OR OLD.code IS DISTINCT FROM NEW.code
                   OR OLD.stage IS DISTINCT FROM NEW.stage
                   OR OLD.details IS DISTINCT FROM NEW.details
                   OR OLD.opened_at IS DISTINCT FROM NEW.opened_at
                   OR OLD.created_at IS DISTINCT FROM NEW.created_at
                   OR OLD.correlation_id IS DISTINCT FROM NEW.correlation_id THEN
                    RAISE EXCEPTION 'REQUEST_BLOCK_IDENTITY_IMMUTABLE';
                END IF;

                IF OLD.resolved_at IS NOT NULL THEN
                    RAISE EXCEPTION 'REQUEST_BLOCK_ALREADY_RESOLVED';
                END IF;
                IF NEW.resolved_at IS NULL AND NEW.resolved_by IS DISTINCT FROM OLD.resolved_by THEN
                    RAISE EXCEPTION 'REQUEST_BLOCK_RESOLVER_REQUIRES_RESOLUTION';
                END IF;

                RETURN NEW;
            END;
            $$;
        SQL);
        DB::statement('CREATE TRIGGER request_blocks_history_trg BEFORE UPDATE OR DELETE ON request_blocks FOR EACH ROW EXECUTE FUNCTION request_block_history_guard()');

        Schema::create('outbox_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_key', 255)->unique();
            $table->string('type', 96);
            $table->unsignedBigInteger('aggregate_id');
            $table->jsonb('payload');
            $table->timestampTz('published_at')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestampTz('available_at')->useCurrent();
            $table->timestampTz('claimed_at')->nullable();
            $table->timestampTz('lease_expires_at')->nullable();
            $table->string('last_error_code', 128)->nullable();
            $table->timestampsTz();

            $table->index(['published_at', 'available_at']);
            $table->index(['lease_expires_at']);
        });

        DB::statement("ALTER TABLE outbox_events ADD CONSTRAINT outbox_events_event_key_check CHECK (length(trim(event_key)) > 0)");
        DB::statement("ALTER TABLE outbox_events ADD CONSTRAINT outbox_events_type_check CHECK (length(trim(type)) > 0)");
        DB::statement('ALTER TABLE outbox_events ADD CONSTRAINT outbox_events_aggregate_check CHECK (aggregate_id > 0)');
        DB::statement("ALTER TABLE outbox_events ADD CONSTRAINT outbox_events_payload_json_check CHECK (jsonb_typeof(payload) = 'object')");
        DB::statement('ALTER TABLE outbox_events ADD CONSTRAINT outbox_events_attempts_check CHECK (attempts >= 0)');
        DB::statement('ALTER TABLE outbox_events ADD CONSTRAINT outbox_events_lease_check CHECK ((claimed_at IS NULL AND lease_expires_at IS NULL) OR (claimed_at IS NOT NULL AND lease_expires_at IS NOT NULL AND lease_expires_at >= claimed_at))');
        DB::statement('ALTER TABLE outbox_events ADD CONSTRAINT outbox_events_published_lease_check CHECK (published_at IS NULL OR (claimed_at IS NULL AND lease_expires_at IS NULL))');
        DB::statement("ALTER TABLE outbox_events ADD CONSTRAINT outbox_events_error_code_check CHECK (last_error_code IS NULL OR length(trim(last_error_code)) > 0)");

        Schema::create('ai_manual_packages', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('ai_execution_id')->unique();
            $table->string('disk', 64);
            $table->string('path', 1024)->unique();
            $table->char('checksum', 64);
            $table->unsignedBigInteger('size_bytes');
            $table->timestampTz('created_at')->useCurrent();
        });

        DB::statement('ALTER TABLE ai_manual_packages ADD CONSTRAINT ai_manual_packages_execution_fk FOREIGN KEY (ai_execution_id) REFERENCES ai_executions(id) ON DELETE RESTRICT');
        DB::statement("ALTER TABLE ai_manual_packages ADD CONSTRAINT ai_manual_packages_disk_check CHECK (length(trim(disk)) > 0)");
        DB::statement("ALTER TABLE ai_manual_packages ADD CONSTRAINT ai_manual_packages_path_check CHECK (length(trim(path)) > 0)");
        DB::statement("ALTER TABLE ai_manual_packages ADD CONSTRAINT ai_manual_packages_checksum_check CHECK (checksum ~ '^[0-9a-f]{64}$')");
        DB::statement('ALTER TABLE ai_manual_packages ADD CONSTRAINT ai_manual_packages_size_check CHECK (size_bytes > 0)');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION outbox_event_identity_guard() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'OUTBOX_EVENT_HISTORY_IMMUTABLE';
                END IF;

                IF OLD.event_key IS DISTINCT FROM NEW.event_key
                   OR OLD.type IS DISTINCT FROM NEW.type
                   OR OLD.aggregate_id IS DISTINCT FROM NEW.aggregate_id
                   OR OLD.payload IS DISTINCT FROM NEW.payload
                   OR OLD.created_at IS DISTINCT FROM NEW.created_at THEN
                    RAISE EXCEPTION 'OUTBOX_EVENT_IDENTITY_IMMUTABLE';
                END IF;

                IF OLD.published_at IS NOT NULL AND OLD IS DISTINCT FROM NEW THEN
                    RAISE EXCEPTION 'OUTBOX_EVENT_PUBLISHED_IMMUTABLE';
                END IF;

                RETURN NEW;
            END;
            $$;
        SQL);
        DB::statement('CREATE TRIGGER outbox_events_identity_trg BEFORE UPDATE OR DELETE ON outbox_events FOR EACH ROW EXECUTE FUNCTION outbox_event_identity_guard()');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION ai_manual_package_immutability_guard() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'AI_MANUAL_PACKAGE_IMMUTABLE';
            END;
            $$;
        SQL);
        DB::statement('CREATE TRIGGER ai_manual_packages_immutability_trg BEFORE UPDATE OR DELETE ON ai_manual_packages FOR EACH ROW EXECUTE FUNCTION ai_manual_package_immutability_guard()');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION planning_generation_integrity_check(target_id bigint) RETURNS void
            LANGUAGE plpgsql AS $$
            DECLARE
                parent planning_requests;
                generation_execution_id bigint;
                generation_correlation_id text;
            BEGIN
                SELECT * INTO parent FROM planning_requests WHERE id = target_id FOR UPDATE;
                IF NOT FOUND OR parent.commercial_authorized_at IS NULL THEN
                    RETURN;
                END IF;

                IF parent.status NOT IN (
                    'GENERACION_IA', 'AUDITORIA_IA', 'CORRECCION_IA', 'REVISION_HUMANA',
                    'APROBADA', 'GENERANDO_DOCUMENTO', 'LISTA_PARA_ENTREGAR', 'ENTREGADA',
                    'COMPLETADA', 'CORRECCION_SOLICITADA'
                ) THEN
                    RETURN;
                END IF;

                IF NOT EXISTS (
                    SELECT 1 FROM usage_reservations
                    WHERE planning_request_id = parent.id
                      AND subscription_period_id = parent.subscription_period_id
                      AND resource = 'planning'
                      AND quantity = parent.planning_units
                      AND status = 'consumed'
                      AND operation_key = 'planning-request:' || parent.id || ':planning'
                ) THEN
                    RAISE EXCEPTION 'AI_GENERATION_PLANNING_CONSUMPTION_REQUIRED';
                END IF;

                SELECT id, input_manifest->>'correlation_id'
                INTO generation_execution_id, generation_correlation_id
                FROM ai_executions
                WHERE request_id = parent.id
                  AND stage = 'generation'
                  AND input_revision = parent.input_revision
                  AND input_manifest->>'input_revision' = parent.input_revision::text
                  AND input_manifest->>'request_input_version_id' = parent.current_version_id::text
                ORDER BY id DESC
                LIMIT 1;

                IF generation_execution_id IS NULL OR generation_correlation_id IS NULL OR generation_correlation_id = '' THEN
                    RAISE EXCEPTION 'AI_GENERATION_EXECUTION_REQUIRED';
                END IF;

                IF NOT EXISTS (
                    SELECT 1 FROM request_state_events
                    WHERE request_id = parent.id
                      AND from_status = 'LISTA_PARA_PROCESAR'
                      AND to_status = 'GENERACION_IA'
                      AND actor_type = 'system'
                      AND reason = 'generation_dispatched'
                      AND correlation_id::text = generation_correlation_id
                ) THEN
                    RAISE EXCEPTION 'AI_GENERATION_STATE_EVENT_REQUIRED';
                END IF;

                IF NOT EXISTS (
                    SELECT 1 FROM outbox_events
                    WHERE event_key = 'ai-execution:' || generation_execution_id || ':generation-dispatch'
                      AND type = 'planning.generation.requested'
                      AND aggregate_id = parent.id
                      AND payload->>'request_id' = parent.id::text
                      AND payload->>'ai_execution_id' = generation_execution_id::text
                      AND payload->>'input_revision' = parent.input_revision::text
                      AND payload->>'correlation_id' = generation_correlation_id
                ) THEN
                    RAISE EXCEPTION 'AI_GENERATION_OUTBOX_REQUIRED';
                END IF;
            END;
            $$;

            CREATE OR REPLACE FUNCTION planning_generation_integrity_trigger() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                PERFORM planning_generation_integrity_check(NEW.id);
                RETURN NULL;
            END;
            $$;
        SQL);
        DB::statement('CREATE CONSTRAINT TRIGGER planning_generation_integrity_trg AFTER INSERT OR UPDATE ON planning_requests DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION planning_generation_integrity_trigger()');
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS planning_generation_integrity_trg ON planning_requests');
        DB::statement('DROP FUNCTION IF EXISTS planning_generation_integrity_trigger()');
        DB::statement('DROP FUNCTION IF EXISTS planning_generation_integrity_check(bigint)');
        DB::statement('DROP TRIGGER IF EXISTS ai_manual_packages_immutability_trg ON ai_manual_packages');
        DB::statement('DROP FUNCTION IF EXISTS ai_manual_package_immutability_guard()');
        Schema::dropIfExists('ai_manual_packages');

        DB::statement('DROP TRIGGER IF EXISTS outbox_events_identity_trg ON outbox_events');
        DB::statement('DROP FUNCTION IF EXISTS outbox_event_identity_guard()');
        Schema::dropIfExists('outbox_events');

        DB::statement('DROP TRIGGER IF EXISTS request_blocks_history_trg ON request_blocks');
        DB::statement('DROP FUNCTION IF EXISTS request_block_history_guard()');
        Schema::dropIfExists('request_blocks');

        DB::statement('DROP TRIGGER IF EXISTS request_state_events_immutability_trg ON request_state_events');
        DB::statement('DROP FUNCTION IF EXISTS request_state_event_immutability_guard()');
    }
};
