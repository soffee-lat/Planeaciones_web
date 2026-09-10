<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        DB::statement('ALTER TABLE files DROP CONSTRAINT files_category_check');
        DB::statement("ALTER TABLE files ADD CONSTRAINT files_category_check CHECK (category IN ('institutional_format','format_sample','book','material','previous_plan','evidence','result','other'))");

        Schema::create('format_version_samples', function (Blueprint $table) {
            $table->id();
            $table->foreignId('format_version_id')->constrained('format_versions')->restrictOnDelete();
            $table->foreignId('source_file_id')->constrained('files')->restrictOnDelete();
            $table->jsonb('mapping_snapshot');
            $table->string('renderer_version', 128);
            $table->char('fingerprint', 64);
            $table->foreignId('docx_file_id')->constrained('files')->restrictOnDelete();
            $table->foreignId('pdf_file_id')->constrained('files')->restrictOnDelete();
            $table->string('status', 24)->default('pending');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('review_note')->nullable();
            $table->timestampTz('reviewed_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['format_version_id', 'fingerprint'], 'format_samples_version_fingerprint_unique');
            $table->index(['format_version_id', 'status']);
        });

        DB::statement("ALTER TABLE format_version_samples ADD CONSTRAINT format_samples_status_check CHECK (status IN ('pending','approved','rejected'))");
        DB::statement("ALTER TABLE format_version_samples ADD CONSTRAINT format_samples_mapping_check CHECK (jsonb_typeof(mapping_snapshot) = 'object')");
        DB::statement("ALTER TABLE format_version_samples ADD CONSTRAINT format_samples_fingerprint_check CHECK (fingerprint ~ '^[0-9a-f]{64}$')");
        DB::statement("ALTER TABLE format_version_samples ADD CONSTRAINT format_samples_files_distinct_check CHECK (docx_file_id <> pdf_file_id)");
        DB::statement("ALTER TABLE format_version_samples ADD CONSTRAINT format_samples_review_check CHECK ((status = 'pending' AND reviewed_by IS NULL AND reviewed_at IS NULL) OR (status IN ('approved','rejected') AND reviewed_by IS NOT NULL AND reviewed_at IS NOT NULL))");
        DB::statement("ALTER TABLE format_version_samples ADD CONSTRAINT format_samples_rejection_note_check CHECK (status <> 'rejected' OR length(trim(COALESCE(review_note,''))) >= 3)");

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION format_sample_history_guard() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'FORMAT_SAMPLE_HISTORY_IMMUTABLE';
    END IF;
    IF ROW(OLD.id, OLD.format_version_id, OLD.source_file_id, OLD.mapping_snapshot,
           OLD.renderer_version, OLD.fingerprint, OLD.docx_file_id, OLD.pdf_file_id,
           OLD.created_by, OLD.created_at)
       IS DISTINCT FROM
       ROW(NEW.id, NEW.format_version_id, NEW.source_file_id, NEW.mapping_snapshot,
           NEW.renderer_version, NEW.fingerprint, NEW.docx_file_id, NEW.pdf_file_id,
           NEW.created_by, NEW.created_at) THEN
        RAISE EXCEPTION 'FORMAT_SAMPLE_IDENTITY_IMMUTABLE';
    END IF;
    IF OLD.status IN ('approved','rejected') AND OLD IS DISTINCT FROM NEW THEN
        RAISE EXCEPTION 'FORMAT_SAMPLE_TERMINAL_IMMUTABLE';
    END IF;
    IF OLD.status = 'pending' AND NEW.status NOT IN ('pending','approved','rejected') THEN
        RAISE EXCEPTION 'FORMAT_SAMPLE_INVALID_TRANSITION';
    END IF;
    RETURN NEW;
END; $$;
SQL);
        DB::statement('CREATE TRIGGER format_samples_history_trg BEFORE UPDATE OR DELETE ON format_version_samples FOR EACH ROW EXECUTE FUNCTION format_sample_history_guard()');

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION format_sample_integrity_check(target_id bigint) RETURNS void
LANGUAGE plpgsql AS $$
DECLARE
    s format_version_samples;
    fv format_versions;
    f institutional_formats;
    source_row files;
    docx_row files;
    pdf_row files;
BEGIN
    SELECT * INTO s FROM format_version_samples WHERE id = target_id;
    IF NOT FOUND THEN RETURN; END IF;

    SELECT * INTO fv FROM format_versions WHERE id = s.format_version_id FOR SHARE;
    IF NOT FOUND THEN RAISE EXCEPTION 'FORMAT_SAMPLE_VERSION_REQUIRED'; END IF;
    SELECT * INTO f FROM institutional_formats WHERE id = fv.format_id FOR SHARE;
    IF NOT FOUND OR f.kind <> 'institutional' OR f.owner_id IS NULL THEN
        RAISE EXCEPTION 'FORMAT_SAMPLE_INSTITUTIONAL_FORMAT_REQUIRED';
    END IF;
    IF fv.source_file_id IS DISTINCT FROM s.source_file_id OR fv.renderer <> 'institutional-v1' THEN
        RAISE EXCEPTION 'FORMAT_SAMPLE_SOURCE_OR_RENDERER_MISMATCH';
    END IF;

    SELECT * INTO source_row FROM files WHERE id = s.source_file_id FOR SHARE;
    IF NOT FOUND OR source_row.owner_id IS DISTINCT FROM f.owner_id OR source_row.request_id IS NOT NULL
       OR source_row.category <> 'institutional_format' OR source_row.scan_status <> 'clean' OR source_row.purged_at IS NOT NULL THEN
        RAISE EXCEPTION 'FORMAT_SAMPLE_SOURCE_NOT_READY';
    END IF;

    SELECT * INTO docx_row FROM files WHERE id = s.docx_file_id FOR SHARE;
    SELECT * INTO pdf_row FROM files WHERE id = s.pdf_file_id FOR SHARE;
    IF docx_row.id IS NULL OR pdf_row.id IS NULL THEN RAISE EXCEPTION 'FORMAT_SAMPLE_OUTPUT_REQUIRED'; END IF;
    IF docx_row.owner_id IS DISTINCT FROM f.owner_id OR pdf_row.owner_id IS DISTINCT FROM f.owner_id
       OR docx_row.request_id IS NOT NULL OR pdf_row.request_id IS NOT NULL
       OR docx_row.category <> 'format_sample' OR pdf_row.category <> 'format_sample'
       OR docx_row.scan_status <> 'clean' OR pdf_row.scan_status <> 'clean'
       OR docx_row.purged_at IS NOT NULL OR pdf_row.purged_at IS NOT NULL
       OR docx_row.detected_mime <> 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
       OR pdf_row.detected_mime <> 'application/pdf' THEN
        RAISE EXCEPTION 'FORMAT_SAMPLE_OUTPUT_INVALID';
    END IF;

    IF s.status = 'approved' THEN
        IF s.mapping_snapshot IS DISTINCT FROM fv.mapping
           OR s.renderer_version <> 'institutional-v1.0.0'
           OR COALESCE(fv.validation_report->>'status','') <> 'approved'
           OR COALESCE(NULLIF(fv.validation_report->'sample'->>'id','')::bigint,0) <> s.id THEN
            RAISE EXCEPTION 'FORMAT_SAMPLE_APPROVAL_STALE';
        END IF;
    END IF;
END; $$;
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION format_sample_integrity_trigger() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
    PERFORM format_sample_integrity_check(NEW.id);
    RETURN NULL;
END; $$;
SQL);
        DB::statement('CREATE TRIGGER format_samples_integrity_trg AFTER INSERT OR UPDATE ON format_version_samples FOR EACH ROW EXECUTE FUNCTION format_sample_integrity_trigger()');

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION institutional_format_publication_sample_check(target_version bigint) RETURNS void
LANGUAGE plpgsql AS $$
DECLARE
    fv format_versions;
    f institutional_formats;
    sample_id bigint;
BEGIN
    SELECT * INTO fv FROM format_versions WHERE id = target_version;
    IF NOT FOUND OR fv.published_at IS NULL THEN RETURN; END IF;
    SELECT * INTO f FROM institutional_formats WHERE id = fv.format_id;
    IF NOT FOUND OR f.kind <> 'institutional' THEN RETURN; END IF;

    sample_id := COALESCE(NULLIF(fv.validation_report->'sample'->>'id','')::bigint,0);
    IF sample_id < 1 OR NOT EXISTS (
        SELECT 1 FROM format_version_samples s
        WHERE s.id = sample_id
          AND s.format_version_id = fv.id
          AND s.status = 'approved'
          AND s.source_file_id = fv.source_file_id
          AND s.mapping_snapshot = fv.mapping
          AND s.renderer_version = 'institutional-v1.0.0'
    ) THEN
        RAISE EXCEPTION 'FORMAT_VERSION_APPROVED_SAMPLE_REQUIRED';
    END IF;
END; $$;
SQL);
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION institutional_format_publication_sample_trigger() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
    PERFORM institutional_format_publication_sample_check(NEW.id);
    RETURN NULL;
END; $$;
SQL);
        DB::statement('CREATE TRIGGER institutional_format_publication_sample_trg AFTER INSERT OR UPDATE OF published_at, validation_report, mapping, source_file_id, renderer ON format_versions FOR EACH ROW EXECUTE FUNCTION institutional_format_publication_sample_trigger()');
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS institutional_format_publication_sample_trg ON format_versions');
        DB::statement('DROP FUNCTION IF EXISTS institutional_format_publication_sample_trigger()');
        DB::statement('DROP FUNCTION IF EXISTS institutional_format_publication_sample_check(bigint)');
        DB::statement('DROP TRIGGER IF EXISTS format_samples_integrity_trg ON format_version_samples');
        DB::statement('DROP FUNCTION IF EXISTS format_sample_integrity_trigger()');
        DB::statement('DROP FUNCTION IF EXISTS format_sample_integrity_check(bigint)');
        DB::statement('DROP TRIGGER IF EXISTS format_samples_history_trg ON format_version_samples');
        DB::statement('DROP FUNCTION IF EXISTS format_sample_history_guard()');
        Schema::dropIfExists('format_version_samples');

        DB::statement('DROP TRIGGER IF EXISTS files_history_trg ON files');
        DB::statement("DELETE FROM files WHERE category = 'format_sample'");
        DB::statement('CREATE TRIGGER files_history_trg BEFORE UPDATE OR DELETE ON files FOR EACH ROW EXECUTE FUNCTION files_history_guard()');
        DB::statement('ALTER TABLE files DROP CONSTRAINT files_category_check');
        DB::statement("ALTER TABLE files ADD CONSTRAINT files_category_check CHECK (category IN ('institutional_format','book','material','previous_plan','evidence','result','other'))");
    }
};
