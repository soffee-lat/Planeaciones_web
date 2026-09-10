<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('files', function (Blueprint $table) {
            $table->timestampTz('retention_until')->nullable()->after('scan_status');
            $table->timestampTz('purged_at')->nullable()->after('retention_until');
            $table->index(['category', 'retention_until', 'purged_at'], 'files_retention_idx');
        });

        Schema::create('deliveries', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('request_id');
            $table->unsignedBigInteger('version_id');
            $table->unsignedBigInteger('render_run_id');
            $table->timestampTz('delivered_at');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->string('idempotency_key', 255)->unique();
            $table->uuid('correlation_id')->unique();
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['request_id', 'delivered_at']);
            $table->unique(['request_id', 'version_id'], 'deliveries_request_version_unique');
        });
        DB::statement('ALTER TABLE deliveries ADD CONSTRAINT deliveries_request_fk FOREIGN KEY (request_id) REFERENCES planning_requests(id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE deliveries ADD CONSTRAINT deliveries_version_fk FOREIGN KEY (version_id) REFERENCES document_versions(id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE deliveries ADD CONSTRAINT deliveries_render_run_fk FOREIGN KEY (render_run_id) REFERENCES document_render_runs(id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE deliveries ADD CONSTRAINT deliveries_created_by_fk FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL');
        DB::statement("ALTER TABLE deliveries ADD CONSTRAINT deliveries_idempotency_key_check CHECK (length(trim(idempotency_key)) > 0)");

        Schema::create('delivery_files', function (Blueprint $table) {
            $table->unsignedBigInteger('delivery_id');
            $table->unsignedBigInteger('file_id');
            $table->string('output_format', 16);
            $table->string('renderer_version', 128);
            $table->timestampTz('created_at')->useCurrent();
            $table->primary(['delivery_id', 'file_id']);
            $table->unique(['delivery_id', 'output_format'], 'delivery_files_output_unique');
        });
        DB::statement('ALTER TABLE delivery_files ADD CONSTRAINT delivery_files_delivery_fk FOREIGN KEY (delivery_id) REFERENCES deliveries(id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE delivery_files ADD CONSTRAINT delivery_files_file_fk FOREIGN KEY (file_id) REFERENCES files(id) ON DELETE RESTRICT');
        DB::statement("ALTER TABLE delivery_files ADD CONSTRAINT delivery_files_output_check CHECK (output_format IN ('docx','pdf'))");
        DB::statement("ALTER TABLE delivery_files ADD CONSTRAINT delivery_files_renderer_check CHECK (length(trim(renderer_version)) > 0)");

        Schema::create('delivery_downloads', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('delivery_id');
            $table->unsignedBigInteger('file_id');
            $table->unsignedBigInteger('user_id');
            $table->timestampTz('downloaded_at')->useCurrent();
            $table->index(['delivery_id', 'downloaded_at']);
            $table->index(['user_id', 'downloaded_at']);
        });
        DB::statement('ALTER TABLE delivery_downloads ADD CONSTRAINT delivery_downloads_delivery_file_fk FOREIGN KEY (delivery_id, file_id) REFERENCES delivery_files(delivery_id, file_id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE delivery_downloads ADD CONSTRAINT delivery_downloads_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT');

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION files_retention_guard() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
    IF OLD.retention_until IS NOT NULL AND NEW.retention_until IS NULL THEN
        RAISE EXCEPTION 'FILE_RETENTION_CANNOT_CLEAR';
    END IF;
    IF OLD.retention_until IS NOT NULL AND NEW.retention_until < OLD.retention_until THEN
        RAISE EXCEPTION 'FILE_RETENTION_CANNOT_SHORTEN';
    END IF;
    IF OLD.purged_at IS NOT NULL AND (NEW.purged_at IS DISTINCT FROM OLD.purged_at OR NEW.retention_until IS DISTINCT FROM OLD.retention_until) THEN
        RAISE EXCEPTION 'FILE_PURGE_STATE_IMMUTABLE';
    END IF;
    IF OLD.purged_at IS NULL AND NEW.purged_at IS NOT NULL THEN
        IF NEW.retention_until IS NULL OR NEW.purged_at < NEW.retention_until THEN
            RAISE EXCEPTION 'FILE_PURGE_BEFORE_RETENTION';
        END IF;
    END IF;
    RETURN NEW;
END; $$;
SQL);
        DB::statement('CREATE TRIGGER files_retention_trg BEFORE UPDATE OF retention_until, purged_at ON files FOR EACH ROW EXECUTE FUNCTION files_retention_guard()');

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION delivery_history_guard() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
    RAISE EXCEPTION 'DELIVERY_HISTORY_IMMUTABLE';
END; $$;
SQL);
        DB::statement('CREATE TRIGGER deliveries_history_trg BEFORE UPDATE OR DELETE ON deliveries FOR EACH ROW EXECUTE FUNCTION delivery_history_guard()');

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION delivery_file_history_guard() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP IN ('UPDATE','DELETE') THEN RAISE EXCEPTION 'DELIVERY_FILE_IMMUTABLE'; END IF;
    RETURN NEW;
END; $$;
SQL);
        DB::statement('CREATE TRIGGER delivery_files_history_trg BEFORE UPDATE OR DELETE ON delivery_files FOR EACH ROW EXECUTE FUNCTION delivery_file_history_guard()');

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION delivery_download_history_guard() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
    RAISE EXCEPTION 'DELIVERY_DOWNLOAD_IMMUTABLE';
END; $$;
SQL);
        DB::statement('CREATE TRIGGER delivery_downloads_history_trg BEFORE UPDATE OR DELETE ON delivery_downloads FOR EACH ROW EXECUTE FUNCTION delivery_download_history_guard()');

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION delivery_download_insert_guard() RETURNS trigger
LANGUAGE plpgsql AS $$
DECLARE request_owner bigint;
BEGIN
    SELECT pr.owner_id INTO request_owner
      FROM deliveries d
      JOIN planning_requests pr ON pr.id = d.request_id
     WHERE d.id = NEW.delivery_id;
    IF request_owner IS NULL OR request_owner IS DISTINCT FROM NEW.user_id THEN
        RAISE EXCEPTION 'DELIVERY_DOWNLOAD_OWNER_MISMATCH';
    END IF;
    RETURN NEW;
END; $$;
SQL);
        DB::statement('CREATE TRIGGER delivery_downloads_insert_trg BEFORE INSERT ON delivery_downloads FOR EACH ROW EXECUTE FUNCTION delivery_download_insert_guard()');

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION planning_delivery_integrity_check(target_request bigint) RETURNS void
LANGUAGE plpgsql AS $$
DECLARE
    parent planning_requests;
    current_version bigint;
    delivery_row deliveries;
    delivery_file_count integer;
    distinct_output_count integer;
    delivery_renderer text;
BEGIN
    SELECT * INTO parent FROM planning_requests WHERE id = target_request FOR UPDATE;
    IF NOT FOUND OR parent.status NOT IN ('ENTREGADA','COMPLETADA','CORRECCION_SOLICITADA') THEN
        RETURN;
    END IF;

    IF parent.commercial_authorized_at IS NULL THEN
        RETURN;
    END IF;

    SELECT d.current_version_id INTO current_version FROM documents d WHERE d.request_id = parent.id;
    IF current_version IS NULL THEN
        RAISE EXCEPTION 'DELIVERY_CURRENT_VERSION_REQUIRED';
    END IF;

    SELECT * INTO delivery_row
      FROM deliveries de
     WHERE de.request_id = parent.id
       AND de.version_id = current_version
     ORDER BY de.id DESC
     LIMIT 1;
    IF delivery_row.id IS NULL THEN
        RAISE EXCEPTION 'DELIVERY_REQUIRED';
    END IF;

    IF NOT EXISTS (
        SELECT 1
          FROM document_versions dv
          JOIN documents d ON d.id = dv.document_id
         WHERE dv.id = delivery_row.version_id
           AND d.request_id = parent.id
           AND d.owner_id = parent.owner_id
    ) THEN
        RAISE EXCEPTION 'DELIVERY_VERSION_MISMATCH';
    END IF;

    SELECT rr.renderer_version INTO delivery_renderer
      FROM document_render_runs rr
     WHERE rr.id = delivery_row.render_run_id
       AND rr.request_id = parent.id
       AND rr.version_id = delivery_row.version_id
       AND rr.status = 'succeeded';
    IF delivery_renderer IS NULL THEN
        RAISE EXCEPTION 'DELIVERY_RENDER_RUN_MISMATCH';
    END IF;

    SELECT count(*), count(DISTINCT df.output_format)
      INTO delivery_file_count, distinct_output_count
      FROM delivery_files df
      JOIN files f ON f.id = df.file_id
     WHERE df.delivery_id = delivery_row.id
       AND f.request_id = parent.id
       AND f.owner_id = parent.owner_id
       AND f.category = 'result'
       AND f.scan_status = 'clean'
       AND f.retention_until IS NOT NULL
       AND f.retention_until >= delivery_row.delivered_at
       AND df.output_format IN ('docx','pdf')
       AND df.renderer_version = delivery_renderer;
    IF delivery_file_count <> 2 OR distinct_output_count <> 2 THEN
        RAISE EXCEPTION 'DELIVERY_FILES_REQUIRED';
    END IF;

    IF EXISTS (
        SELECT 1
          FROM delivery_files df
          LEFT JOIN document_version_files dvf
            ON dvf.version_id = delivery_row.version_id
           AND dvf.file_id = df.file_id
           AND dvf.output_format = df.output_format
           AND dvf.renderer_version = df.renderer_version
         WHERE df.delivery_id = delivery_row.id
           AND dvf.file_id IS NULL
    ) THEN
        RAISE EXCEPTION 'DELIVERY_FILE_VERSION_MISMATCH';
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM request_state_events e
         WHERE e.request_id = parent.id
           AND e.from_status = 'LISTA_PARA_ENTREGAR'
           AND e.to_status = 'ENTREGADA'
           AND e.reason = 'delivery_published'
           AND e.correlation_id = delivery_row.correlation_id
    ) THEN
        RAISE EXCEPTION 'DELIVERY_STATE_EVENT_REQUIRED';
    END IF;
END; $$;
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION planning_delivery_integrity_trigger() RETURNS trigger
LANGUAGE plpgsql AS $$
DECLARE target_id bigint;
BEGIN
    IF TG_TABLE_NAME = 'planning_requests' THEN
        target_id := NEW.id;
    ELSIF TG_TABLE_NAME = 'deliveries' THEN
        target_id := NEW.request_id;
    ELSIF TG_TABLE_NAME = 'delivery_files' THEN
        SELECT request_id INTO target_id FROM deliveries WHERE id = NEW.delivery_id;
    ELSIF TG_TABLE_NAME = 'request_state_events' THEN
        target_id := NEW.request_id;
    ELSE
        target_id := NEW.request_id;
    END IF;
    IF target_id IS NOT NULL THEN PERFORM planning_delivery_integrity_check(target_id); END IF;
    RETURN NULL;
END; $$;
SQL);
        DB::statement('CREATE CONSTRAINT TRIGGER planning_delivery_requests_integrity_trg AFTER INSERT OR UPDATE ON planning_requests DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION planning_delivery_integrity_trigger()');
        DB::statement('CREATE CONSTRAINT TRIGGER planning_deliveries_integrity_trg AFTER INSERT ON deliveries DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION planning_delivery_integrity_trigger()');
        DB::statement('CREATE CONSTRAINT TRIGGER planning_delivery_files_integrity_trg AFTER INSERT ON delivery_files DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION planning_delivery_integrity_trigger()');
        DB::statement('CREATE CONSTRAINT TRIGGER planning_delivery_events_integrity_trg AFTER INSERT ON request_state_events DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION planning_delivery_integrity_trigger()');
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS planning_delivery_events_integrity_trg ON request_state_events');
        DB::statement('DROP TRIGGER IF EXISTS planning_delivery_files_integrity_trg ON delivery_files');
        DB::statement('DROP TRIGGER IF EXISTS planning_deliveries_integrity_trg ON deliveries');
        DB::statement('DROP TRIGGER IF EXISTS planning_delivery_requests_integrity_trg ON planning_requests');
        DB::statement('DROP FUNCTION IF EXISTS planning_delivery_integrity_trigger()');
        DB::statement('DROP FUNCTION IF EXISTS planning_delivery_integrity_check(bigint)');
        DB::statement('DROP TRIGGER IF EXISTS delivery_downloads_insert_trg ON delivery_downloads');
        DB::statement('DROP FUNCTION IF EXISTS delivery_download_insert_guard()');
        DB::statement('DROP TRIGGER IF EXISTS delivery_downloads_history_trg ON delivery_downloads');
        DB::statement('DROP FUNCTION IF EXISTS delivery_download_history_guard()');
        DB::statement('DROP TRIGGER IF EXISTS delivery_files_history_trg ON delivery_files');
        DB::statement('DROP FUNCTION IF EXISTS delivery_file_history_guard()');
        DB::statement('DROP TRIGGER IF EXISTS deliveries_history_trg ON deliveries');
        DB::statement('DROP FUNCTION IF EXISTS delivery_history_guard()');
        DB::statement('DROP TRIGGER IF EXISTS files_retention_trg ON files');
        DB::statement('DROP FUNCTION IF EXISTS files_retention_guard()');
        Schema::dropIfExists('delivery_downloads');
        Schema::dropIfExists('delivery_files');
        Schema::dropIfExists('deliveries');
        Schema::table('files', function (Blueprint $table) {
            $table->dropIndex('files_retention_idx');
            $table->dropColumn(['retention_until', 'purged_at']);
        });
    }
};
