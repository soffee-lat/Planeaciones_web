<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    private const CURRENT_RENDERER_VERSION = 'institutional-v1.2.0';
    private const PREVIOUS_RENDERER_VERSION = 'institutional-v1.1.0';

    public function up(): void
    {
        $this->install(self::CURRENT_RENDERER_VERSION);
    }

    public function down(): void
    {
        $this->install(self::PREVIOUS_RENDERER_VERSION);
    }

    private function install(string $rendererVersion): void
    {
        $rendererVersion = str_replace("'", "''", $rendererVersion);

        DB::unprepared(<<<SQL
CREATE OR REPLACE FUNCTION format_sample_integrity_check(target_id bigint) RETURNS void
LANGUAGE plpgsql AS \$\$
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
           OR s.renderer_version <> '{$rendererVersion}'
           OR COALESCE(fv.validation_report->>'status','') <> 'approved'
           OR COALESCE(NULLIF(fv.validation_report->'sample'->>'id','')::bigint,0) <> s.id THEN
            RAISE EXCEPTION 'FORMAT_SAMPLE_APPROVAL_STALE';
        END IF;
    END IF;
END; \$\$;
SQL);

        DB::unprepared(<<<SQL
CREATE OR REPLACE FUNCTION institutional_format_publication_sample_check(target_version bigint) RETURNS void
LANGUAGE plpgsql AS \$\$
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
          AND s.renderer_version = '{$rendererVersion}'
    ) THEN
        RAISE EXCEPTION 'FORMAT_VERSION_APPROVED_SAMPLE_REQUIRED';
    END IF;
END; \$\$;
SQL);
    }
};
