<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('document_render_runs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('request_id');
            $table->unsignedBigInteger('version_id');
            $table->unsignedBigInteger('format_version_id');
            $table->string('operation_key', 512)->unique();
            $table->uuid('correlation_id');
            $table->string('renderer_version', 128);
            $table->string('status', 24);
            $table->unsignedInteger('attempts')->default(0);
            $table->jsonb('manifest');
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->string('last_error_code', 128)->nullable();
            $table->timestampsTz();
            $table->index(['request_id', 'status']);
            $table->index(['version_id', 'status']);
        });

        DB::statement('ALTER TABLE document_render_runs ADD CONSTRAINT document_render_runs_request_fk FOREIGN KEY (request_id) REFERENCES planning_requests(id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE document_render_runs ADD CONSTRAINT document_render_runs_version_fk FOREIGN KEY (version_id) REFERENCES document_versions(id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE document_render_runs ADD CONSTRAINT document_render_runs_format_version_fk FOREIGN KEY (format_version_id) REFERENCES format_versions(id) ON DELETE RESTRICT');
        DB::statement("ALTER TABLE document_render_runs ADD CONSTRAINT document_render_runs_status_check CHECK (status IN ('pending','running','failed','succeeded'))");
        DB::statement('ALTER TABLE document_render_runs ADD CONSTRAINT document_render_runs_attempts_check CHECK (attempts >= 0)');
        DB::statement("ALTER TABLE document_render_runs ADD CONSTRAINT document_render_runs_manifest_object_check CHECK (jsonb_typeof(manifest) = 'object')");
        DB::statement("ALTER TABLE document_render_runs ALTER COLUMN manifest SET DEFAULT '{}'::jsonb");
        DB::statement("ALTER TABLE document_render_runs ADD CONSTRAINT document_render_runs_identity_text_check CHECK (length(trim(operation_key)) > 0 AND length(trim(renderer_version)) > 0)");
        DB::statement("ALTER TABLE document_render_runs ADD CONSTRAINT document_render_runs_state_shape_check CHECK (
            (status = 'pending' AND started_at IS NULL AND finished_at IS NULL AND last_error_code IS NULL)
            OR (status = 'running' AND started_at IS NOT NULL AND finished_at IS NULL AND last_error_code IS NULL)
            OR (status = 'failed' AND finished_at IS NOT NULL AND last_error_code IS NOT NULL AND length(trim(last_error_code)) > 0)
            OR (status = 'succeeded' AND started_at IS NOT NULL AND finished_at IS NOT NULL AND last_error_code IS NULL
                AND manifest->>'schema_version' = 'document_render_manifest_v1'
                AND jsonb_typeof(manifest->'outputs') = 'array'
                AND jsonb_array_length(manifest->'outputs') = 2)
        )");

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION document_render_run_guard() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'DOCUMENT_RENDER_HISTORY_IMMUTABLE';
    END IF;

    IF OLD.request_id IS DISTINCT FROM NEW.request_id
       OR OLD.version_id IS DISTINCT FROM NEW.version_id
       OR OLD.format_version_id IS DISTINCT FROM NEW.format_version_id
       OR OLD.operation_key IS DISTINCT FROM NEW.operation_key
       OR OLD.correlation_id IS DISTINCT FROM NEW.correlation_id
       OR OLD.renderer_version IS DISTINCT FROM NEW.renderer_version
       OR OLD.created_at IS DISTINCT FROM NEW.created_at THEN
        RAISE EXCEPTION 'DOCUMENT_RENDER_IDENTITY_IMMUTABLE';
    END IF;

    IF OLD.status = 'succeeded' AND OLD IS DISTINCT FROM NEW THEN
        RAISE EXCEPTION 'DOCUMENT_RENDER_SUCCEEDED_IMMUTABLE';
    END IF;

    IF NEW.attempts < OLD.attempts THEN
        RAISE EXCEPTION 'DOCUMENT_RENDER_ATTEMPTS_CANNOT_DECREASE';
    END IF;

    IF OLD.status = 'pending' AND NEW.status NOT IN ('pending','running','failed') THEN
        RAISE EXCEPTION 'DOCUMENT_RENDER_STATUS_TRANSITION_INVALID';
    ELSIF OLD.status = 'running' AND NEW.status NOT IN ('running','failed','succeeded') THEN
        RAISE EXCEPTION 'DOCUMENT_RENDER_STATUS_TRANSITION_INVALID';
    ELSIF OLD.status = 'failed' AND NEW.status NOT IN ('failed','running') THEN
        RAISE EXCEPTION 'DOCUMENT_RENDER_STATUS_TRANSITION_INVALID';
    END IF;

    IF OLD.manifest IS DISTINCT FROM NEW.manifest AND NEW.status <> 'succeeded' THEN
        RAISE EXCEPTION 'DOCUMENT_RENDER_MANIFEST_ONLY_ON_SUCCESS';
    END IF;

    RETURN NEW;
END; $$;
SQL);
        DB::statement('CREATE TRIGGER document_render_runs_guard_trg BEFORE UPDATE OR DELETE ON document_render_runs FOR EACH ROW EXECUTE FUNCTION document_render_run_guard()');

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION document_render_request_integrity_check(target_request bigint) RETURNS void
LANGUAGE plpgsql AS $$
DECLARE
    parent planning_requests;
    current_document_id bigint;
    current_version_id bigint;
    current_content_hash text;
    run_row document_render_runs;
    format_owner bigint;
    format_status text;
    format_published timestamptz;
    expected_operation_key text;
    output_count integer;
    distinct_output_count integer;
BEGIN
    SELECT * INTO parent FROM planning_requests WHERE id = target_request FOR UPDATE;
    IF NOT FOUND OR parent.status NOT IN ('GENERANDO_DOCUMENTO','LISTA_PARA_ENTREGAR','ENTREGADA','COMPLETADA','CORRECCION_SOLICITADA') THEN
        RETURN;
    END IF;

    -- Commercial authorization is a prerequisite owned by the Phase 3 integrity
    -- trigger. Do not shadow its more fundamental AUTHORIZATION_REQUIRED error
    -- with document-render errors when a request was moved forward by direct SQL.
    IF parent.commercial_authorized_at IS NULL THEN
        RETURN;
    END IF;

    SELECT d.id, d.current_version_id, dv.content_hash
      INTO current_document_id, current_version_id, current_content_hash
      FROM documents d
      JOIN document_versions dv ON dv.id = d.current_version_id
     WHERE d.request_id = parent.id;

    IF current_document_id IS NULL OR current_version_id IS NULL THEN
        RAISE EXCEPTION 'DOCUMENT_RENDER_CURRENT_VERSION_REQUIRED';
    END IF;

    SELECT * INTO run_row
      FROM document_render_runs rr
     WHERE rr.request_id = parent.id
       AND rr.version_id = current_version_id
     ORDER BY rr.id DESC
     LIMIT 1;

    IF run_row.id IS NULL THEN
        RAISE EXCEPTION 'DOCUMENT_RENDER_RUN_REQUIRED';
    END IF;

    expected_operation_key := 'planning-request:' || parent.id || ':render:version:' || current_version_id
        || ':format:' || run_row.format_version_id || ':renderer:' || run_row.renderer_version;
    IF run_row.operation_key <> expected_operation_key THEN
        RAISE EXCEPTION 'DOCUMENT_RENDER_OPERATION_KEY_INVALID';
    END IF;

    SELECT f.owner_id, f.status, fv.published_at
      INTO format_owner, format_status, format_published
      FROM format_versions fv
      JOIN institutional_formats f ON f.id = fv.format_id
     WHERE fv.id = run_row.format_version_id;

    IF format_published IS NULL OR format_status <> 'ready'
       OR (format_owner IS NOT NULL AND format_owner IS DISTINCT FROM parent.owner_id) THEN
        RAISE EXCEPTION 'DOCUMENT_RENDER_FORMAT_NOT_USABLE';
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM request_state_events e
         WHERE e.request_id = parent.id
           AND e.from_status = 'APROBADA'
           AND e.to_status = 'GENERANDO_DOCUMENTO'
           AND e.actor_type = 'system'
           AND e.reason = 'document_render_dispatched'
           AND e.correlation_id = run_row.correlation_id
    ) THEN
        RAISE EXCEPTION 'DOCUMENT_RENDER_DISPATCH_EVENT_REQUIRED';
    END IF;

    IF parent.status = 'GENERANDO_DOCUMENTO' THEN
        RETURN;
    END IF;

    IF run_row.status <> 'succeeded' THEN
        RAISE EXCEPTION 'DOCUMENT_RENDER_SUCCESS_REQUIRED';
    END IF;

    IF run_row.manifest->>'request_id' <> parent.id::text
       OR run_row.manifest->>'version_id' <> current_version_id::text
       OR run_row.manifest->>'format_version_id' <> run_row.format_version_id::text
       OR run_row.manifest->>'renderer_version' <> run_row.renderer_version
       OR run_row.manifest->>'source_content_hash' <> current_content_hash THEN
        RAISE EXCEPTION 'DOCUMENT_RENDER_MANIFEST_IDENTITY_MISMATCH';
    END IF;

    SELECT count(*), count(DISTINCT dvf.output_format)
      INTO output_count, distinct_output_count
      FROM document_version_files dvf
     WHERE dvf.version_id = current_version_id
       AND dvf.renderer_version = run_row.renderer_version
       AND dvf.output_format IN ('docx','pdf');

    IF output_count <> 2 OR distinct_output_count <> 2 THEN
        RAISE EXCEPTION 'DOCUMENT_RENDER_OUTPUT_FILES_REQUIRED';
    END IF;

    IF EXISTS (
        SELECT 1
          FROM jsonb_array_elements(run_row.manifest->'outputs') o
          LEFT JOIN document_version_files dvf
            ON dvf.version_id = current_version_id
           AND dvf.renderer_version = run_row.renderer_version
           AND dvf.output_format = o->>'format'
          LEFT JOIN files f ON f.id = dvf.file_id
         WHERE dvf.file_id IS NULL
            OR f.id::text <> o->>'file_id'
            OR f.sha256 <> o->>'sha256'
            OR f.size_bytes::text <> o->>'size_bytes'
            OR f.detected_mime <> o->>'mime'
            OR f.path <> o->>'path'
            OR f.owner_id <> parent.owner_id
            OR f.request_id <> parent.id
            OR f.category <> 'result'
            OR f.scan_status <> 'clean'
    ) THEN
        RAISE EXCEPTION 'DOCUMENT_RENDER_MANIFEST_OUTPUT_MISMATCH';
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM request_state_events e
         WHERE e.request_id = parent.id
           AND e.from_status = 'GENERANDO_DOCUMENTO'
           AND e.to_status = 'LISTA_PARA_ENTREGAR'
           AND e.actor_type = 'system'
           AND e.reason = 'document_render_succeeded'
           AND e.correlation_id = run_row.correlation_id
    ) THEN
        RAISE EXCEPTION 'DOCUMENT_RENDER_READY_EVENT_REQUIRED';
    END IF;
END; $$;
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION document_render_request_integrity_trigger() RETURNS trigger
LANGUAGE plpgsql AS $$
DECLARE target_id bigint;
BEGIN
    IF TG_TABLE_NAME = 'planning_requests' THEN
        target_id := NEW.id;
    ELSIF TG_TABLE_NAME = 'document_render_runs' THEN
        target_id := NEW.request_id;
    ELSIF TG_TABLE_NAME = 'request_state_events' THEN
        target_id := NEW.request_id;
    ELSE
        SELECT d.request_id INTO target_id
          FROM document_versions dv JOIN documents d ON d.id = dv.document_id
         WHERE dv.id = NEW.version_id;
    END IF;

    IF target_id IS NOT NULL THEN
        PERFORM document_render_request_integrity_check(target_id);
    END IF;
    RETURN NULL;
END; $$;
SQL);

        DB::statement('CREATE CONSTRAINT TRIGGER document_render_requests_integrity_trg AFTER INSERT OR UPDATE ON planning_requests DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION document_render_request_integrity_trigger()');
        DB::statement('CREATE CONSTRAINT TRIGGER document_render_runs_integrity_trg AFTER INSERT OR UPDATE ON document_render_runs DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION document_render_request_integrity_trigger()');
        DB::statement('CREATE CONSTRAINT TRIGGER document_render_events_integrity_trg AFTER INSERT ON request_state_events DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION document_render_request_integrity_trigger()');
        DB::statement('CREATE CONSTRAINT TRIGGER document_render_files_integrity_trg AFTER INSERT ON document_version_files DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION document_render_request_integrity_trigger()');
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS document_render_files_integrity_trg ON document_version_files');
        DB::statement('DROP TRIGGER IF EXISTS document_render_events_integrity_trg ON request_state_events');
        DB::statement('DROP TRIGGER IF EXISTS document_render_runs_integrity_trg ON document_render_runs');
        DB::statement('DROP TRIGGER IF EXISTS document_render_requests_integrity_trg ON planning_requests');
        DB::statement('DROP FUNCTION IF EXISTS document_render_request_integrity_trigger()');
        DB::statement('DROP FUNCTION IF EXISTS document_render_request_integrity_check(bigint)');
        DB::statement('DROP TRIGGER IF EXISTS document_render_runs_guard_trg ON document_render_runs');
        DB::statement('DROP FUNCTION IF EXISTS document_render_run_guard()');
        Schema::dropIfExists('document_render_runs');
    }
};
