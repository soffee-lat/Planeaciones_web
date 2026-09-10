<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('files', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('owner_id');
            $table->unsignedBigInteger('request_id')->nullable();
            $table->string('category', 40);
            $table->string('disk', 64);
            $table->string('path', 1024)->unique();
            $table->string('original_name', 255);
            $table->string('detected_mime', 255);
            $table->unsignedBigInteger('size_bytes');
            $table->char('sha256', 64);
            $table->string('scan_status', 24)->default('pending');
            $table->unsignedBigInteger('uploaded_by')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['owner_id', 'category']);
            $table->index(['request_id', 'category']);
        });
        DB::statement('ALTER TABLE files ADD CONSTRAINT files_owner_fk FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE files ADD CONSTRAINT files_request_owner_fk FOREIGN KEY (request_id, owner_id) REFERENCES planning_requests(id, owner_id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE files ADD CONSTRAINT files_uploaded_by_fk FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL');
        DB::statement("ALTER TABLE files ADD CONSTRAINT files_category_check CHECK (category IN ('institutional_format','book','material','previous_plan','evidence','result','other'))");
        DB::statement("ALTER TABLE files ADD CONSTRAINT files_scan_status_check CHECK (scan_status IN ('pending','clean','quarantined','failed'))");
        DB::statement("ALTER TABLE files ADD CONSTRAINT files_sha256_check CHECK (sha256 ~ '^[0-9a-f]{64}$')");
        DB::statement("ALTER TABLE files ADD CONSTRAINT files_path_check CHECK (length(trim(path)) > 0 AND length(trim(disk)) > 0 AND length(trim(original_name)) > 0 AND length(trim(detected_mime)) > 0)");

        Schema::create('institutional_formats', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('owner_id')->nullable();
            $table->string('name', 255);
            $table->string('kind', 24);
            $table->string('status', 32);
            $table->timestampsTz();
            $table->index(['owner_id', 'status']);
        });
        DB::statement('ALTER TABLE institutional_formats ADD CONSTRAINT institutional_formats_owner_fk FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE RESTRICT');
        DB::statement("ALTER TABLE institutional_formats ADD CONSTRAINT institutional_formats_kind_check CHECK (kind IN ('standard','institutional'))");
        DB::statement("ALTER TABLE institutional_formats ADD CONSTRAINT institutional_formats_status_check CHECK (status IN ('pending_analysis','configuring','ready','unsupported','archived'))");
        DB::statement("ALTER TABLE institutional_formats ADD CONSTRAINT institutional_formats_owner_kind_check CHECK ((kind = 'standard' AND owner_id IS NULL) OR (kind = 'institutional' AND owner_id IS NOT NULL))");
        DB::statement("ALTER TABLE institutional_formats ADD CONSTRAINT institutional_formats_name_check CHECK (length(trim(name)) > 0)");
        DB::statement("CREATE UNIQUE INDEX institutional_formats_single_standard_idx ON institutional_formats ((kind)) WHERE kind = 'standard' AND status <> 'archived'");

        Schema::create('format_versions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('format_id');
            $table->unsignedInteger('number');
            $table->unsignedBigInteger('source_file_id')->nullable();
            $table->jsonb('mapping');
            $table->unsignedInteger('schema_version');
            $table->string('renderer', 128);
            $table->jsonb('validation_report')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestampTz('published_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['format_id', 'number']);
            $table->index(['format_id', 'published_at']);
        });
        DB::statement('ALTER TABLE format_versions ADD CONSTRAINT format_versions_format_fk FOREIGN KEY (format_id) REFERENCES institutional_formats(id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE format_versions ADD CONSTRAINT format_versions_source_file_fk FOREIGN KEY (source_file_id) REFERENCES files(id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE format_versions ADD CONSTRAINT format_versions_approved_by_fk FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE format_versions ADD CONSTRAINT format_versions_number_check CHECK (number > 0 AND schema_version > 0)');
        DB::statement("ALTER TABLE format_versions ADD CONSTRAINT format_versions_mapping_object_check CHECK (jsonb_typeof(mapping) = 'object')");
        DB::statement("ALTER TABLE format_versions ADD CONSTRAINT format_versions_validation_object_check CHECK (validation_report IS NULL OR jsonb_typeof(validation_report) = 'object')");
        DB::statement("ALTER TABLE format_versions ADD CONSTRAINT format_versions_renderer_check CHECK (length(trim(renderer)) > 0)");

        Schema::table('group_profiles', function (Blueprint $table) {
            $table->foreign('preferred_format_id', 'group_profiles_preferred_format_fk')->references('id')->on('institutional_formats')->restrictOnDelete();
        });
        Schema::table('planning_requests', function (Blueprint $table) {
            $table->unsignedBigInteger('format_version_id')->nullable();
            $table->foreign('format_version_id', 'planning_requests_format_version_fk')->references('id')->on('format_versions')->restrictOnDelete();
        });
        Schema::table('ai_executions', function (Blueprint $table) {
            $table->foreign('format_version_id', 'ai_executions_format_version_fk')->references('id')->on('format_versions')->restrictOnDelete();
            $table->foreign('private_payload_file_id', 'ai_executions_private_payload_file_fk')->references('id')->on('files')->restrictOnDelete();
        });

        Schema::create('document_version_files', function (Blueprint $table) {
            $table->unsignedBigInteger('version_id');
            $table->unsignedBigInteger('file_id');
            $table->string('output_format', 16);
            $table->string('renderer_version', 128);
            $table->timestampTz('created_at')->useCurrent();
            $table->primary(['version_id', 'file_id']);
            $table->unique(['version_id', 'output_format', 'renderer_version'], 'document_version_files_output_unique');
        });
        DB::statement('ALTER TABLE document_version_files ADD CONSTRAINT document_version_files_version_fk FOREIGN KEY (version_id) REFERENCES document_versions(id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE document_version_files ADD CONSTRAINT document_version_files_file_fk FOREIGN KEY (file_id) REFERENCES files(id) ON DELETE RESTRICT');
        DB::statement("ALTER TABLE document_version_files ADD CONSTRAINT document_version_files_output_check CHECK (output_format IN ('docx','pdf'))");
        DB::statement("ALTER TABLE document_version_files ADD CONSTRAINT document_version_files_renderer_check CHECK (length(trim(renderer_version)) > 0)");

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION files_history_guard() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'FILE_HISTORY_IMMUTABLE'; END IF;
    IF OLD.owner_id IS DISTINCT FROM NEW.owner_id OR OLD.request_id IS DISTINCT FROM NEW.request_id OR OLD.category IS DISTINCT FROM NEW.category
       OR OLD.disk IS DISTINCT FROM NEW.disk OR OLD.path IS DISTINCT FROM NEW.path OR OLD.original_name IS DISTINCT FROM NEW.original_name
       OR OLD.detected_mime IS DISTINCT FROM NEW.detected_mime OR OLD.size_bytes IS DISTINCT FROM NEW.size_bytes OR OLD.sha256 IS DISTINCT FROM NEW.sha256
       OR OLD.uploaded_by IS DISTINCT FROM NEW.uploaded_by THEN RAISE EXCEPTION 'FILE_IDENTITY_IMMUTABLE'; END IF;
    IF OLD.scan_status <> 'pending' AND OLD.scan_status IS DISTINCT FROM NEW.scan_status THEN RAISE EXCEPTION 'FILE_SCAN_STATUS_TERMINAL'; END IF;
    RETURN NEW;
END; $$;
SQL);
        DB::statement('CREATE TRIGGER files_history_trg BEFORE UPDATE OR DELETE ON files FOR EACH ROW EXECUTE FUNCTION files_history_guard()');

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION format_version_publication_guard() RETURNS trigger
LANGUAGE plpgsql AS $$
DECLARE f_owner bigint; f_kind text; f_status text; sf_owner bigint; sf_category text; sf_scan text;
BEGIN
    IF TG_OP = 'DELETE' THEN
        IF OLD.published_at IS NOT NULL THEN RAISE EXCEPTION 'FORMAT_VERSION_PUBLISHED_IMMUTABLE'; END IF;
        RETURN OLD;
    END IF;
    IF TG_OP = 'UPDATE' AND OLD.published_at IS NOT NULL THEN RAISE EXCEPTION 'FORMAT_VERSION_PUBLISHED_IMMUTABLE'; END IF;
    IF NEW.published_at IS NOT NULL THEN
        SELECT owner_id, kind, status INTO f_owner, f_kind, f_status FROM institutional_formats WHERE id = NEW.format_id FOR SHARE;
        IF f_status <> 'ready' THEN RAISE EXCEPTION 'FORMAT_VERSION_FORMAT_NOT_READY'; END IF;
        IF COALESCE(NEW.validation_report->>'status','') <> 'approved' THEN RAISE EXCEPTION 'FORMAT_VERSION_SAMPLE_NOT_APPROVED'; END IF;
        IF f_kind = 'institutional' THEN
            IF NEW.approved_by IS NULL OR NEW.source_file_id IS NULL THEN RAISE EXCEPTION 'FORMAT_VERSION_INSTITUTIONAL_PUBLICATION_INCOMPLETE'; END IF;
            SELECT owner_id, category, scan_status INTO sf_owner, sf_category, sf_scan FROM files WHERE id = NEW.source_file_id FOR SHARE;
            IF sf_owner IS DISTINCT FROM f_owner OR sf_category <> 'institutional_format' OR sf_scan <> 'clean' THEN RAISE EXCEPTION 'FORMAT_VERSION_SOURCE_NOT_READY'; END IF;
        ELSE
            IF NEW.source_file_id IS NOT NULL OR NEW.approved_by IS NOT NULL THEN RAISE EXCEPTION 'FORMAT_VERSION_STANDARD_SYSTEM_MANAGED'; END IF;
        END IF;
    END IF;
    RETURN NEW;
END; $$;
SQL);
        DB::statement('CREATE TRIGGER format_versions_publication_trg BEFORE INSERT OR UPDATE OR DELETE ON format_versions FOR EACH ROW EXECUTE FUNCTION format_version_publication_guard()');

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION group_profile_format_guard() RETURNS trigger
LANGUAGE plpgsql AS $$
DECLARE format_owner bigint; format_status text; group_owner bigint;
BEGIN
    IF NEW.preferred_format_id IS NULL THEN RETURN NEW; END IF;
    SELECT owner_id, status INTO format_owner, format_status FROM institutional_formats WHERE id = NEW.preferred_format_id FOR SHARE;
    SELECT owner_id INTO group_owner FROM groups WHERE id = NEW.group_id FOR SHARE;
    IF format_status <> 'ready' THEN RAISE EXCEPTION 'GROUP_PREFERRED_FORMAT_NOT_READY'; END IF;
    IF format_owner IS NOT NULL AND format_owner IS DISTINCT FROM group_owner THEN RAISE EXCEPTION 'GROUP_PREFERRED_FORMAT_OWNER_MISMATCH'; END IF;
    RETURN NEW;
END; $$;
SQL);
        DB::statement('CREATE TRIGGER group_profiles_format_trg BEFORE INSERT OR UPDATE OF preferred_format_id, group_id ON group_profiles FOR EACH ROW EXECUTE FUNCTION group_profile_format_guard()');

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION planning_request_format_guard() RETURNS trigger
LANGUAGE plpgsql AS $$
DECLARE format_owner bigint; format_status text; published timestamptz;
BEGIN
    IF NEW.format_version_id IS NULL THEN RETURN NEW; END IF;
    SELECT f.owner_id, f.status, fv.published_at INTO format_owner, format_status, published
      FROM format_versions fv JOIN institutional_formats f ON f.id = fv.format_id WHERE fv.id = NEW.format_version_id FOR SHARE OF fv, f;
    IF published IS NULL OR format_status <> 'ready' THEN RAISE EXCEPTION 'PLANNING_FORMAT_VERSION_NOT_USABLE'; END IF;
    IF format_owner IS NOT NULL AND format_owner IS DISTINCT FROM NEW.owner_id THEN RAISE EXCEPTION 'PLANNING_FORMAT_OWNER_MISMATCH'; END IF;
    RETURN NEW;
END; $$;
SQL);
        DB::statement('CREATE TRIGGER planning_requests_format_trg BEFORE INSERT OR UPDATE OF format_version_id, owner_id ON planning_requests FOR EACH ROW EXECUTE FUNCTION planning_request_format_guard()');

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION document_version_file_integrity_check(target_version bigint, target_file bigint) RETURNS void
LANGUAGE plpgsql AS $$
DECLARE doc_request bigint; doc_owner bigint; file_request bigint; file_owner bigint; file_category text; file_scan text;
BEGIN
    SELECT d.request_id, d.owner_id INTO doc_request, doc_owner FROM document_versions dv JOIN documents d ON d.id = dv.document_id WHERE dv.id = target_version;
    SELECT request_id, owner_id, category, scan_status INTO file_request, file_owner, file_category, file_scan FROM files WHERE id = target_file;
    IF file_category <> 'result' OR file_scan <> 'clean' THEN RAISE EXCEPTION 'DOCUMENT_OUTPUT_FILE_NOT_READY'; END IF;
    IF file_request IS DISTINCT FROM doc_request OR file_owner IS DISTINCT FROM doc_owner THEN RAISE EXCEPTION 'DOCUMENT_OUTPUT_FILE_OWNER_MISMATCH'; END IF;
END; $$;
SQL);
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION document_version_file_integrity_trigger() RETURNS trigger
LANGUAGE plpgsql AS $$ BEGIN
    IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'DOCUMENT_VERSION_FILE_IMMUTABLE'; END IF;
    IF TG_OP = 'UPDATE' THEN RAISE EXCEPTION 'DOCUMENT_VERSION_FILE_IMMUTABLE'; END IF;
    PERFORM document_version_file_integrity_check(NEW.version_id, NEW.file_id); RETURN NEW;
END; $$;
SQL);
        DB::statement('CREATE CONSTRAINT TRIGGER document_version_files_integrity_trg AFTER INSERT OR UPDATE OR DELETE ON document_version_files DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION document_version_file_integrity_trigger()');
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS document_version_files_integrity_trg ON document_version_files');
        DB::statement('DROP FUNCTION IF EXISTS document_version_file_integrity_trigger()');
        DB::statement('DROP FUNCTION IF EXISTS document_version_file_integrity_check(bigint,bigint)');
        DB::statement('DROP TRIGGER IF EXISTS planning_requests_format_trg ON planning_requests');
        DB::statement('DROP FUNCTION IF EXISTS planning_request_format_guard()');
        DB::statement('DROP TRIGGER IF EXISTS group_profiles_format_trg ON group_profiles');
        DB::statement('DROP FUNCTION IF EXISTS group_profile_format_guard()');
        DB::statement('DROP TRIGGER IF EXISTS format_versions_publication_trg ON format_versions');
        DB::statement('DROP FUNCTION IF EXISTS format_version_publication_guard()');
        DB::statement('DROP TRIGGER IF EXISTS files_history_trg ON files');
        DB::statement('DROP FUNCTION IF EXISTS files_history_guard()');
        Schema::dropIfExists('document_version_files');
        Schema::table('ai_executions', function (Blueprint $table) {
            $table->dropForeign('ai_executions_format_version_fk');
            $table->dropForeign('ai_executions_private_payload_file_fk');
        });
        Schema::table('planning_requests', function (Blueprint $table) {
            $table->dropForeign('planning_requests_format_version_fk');
            $table->dropColumn('format_version_id');
        });
        Schema::table('group_profiles', function (Blueprint $table) {
            $table->dropForeign('group_profiles_preferred_format_fk');
        });
        Schema::dropIfExists('format_versions');
        Schema::dropIfExists('institutional_formats');
        Schema::dropIfExists('files');
    }
};
