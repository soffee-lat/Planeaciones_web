<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // source_reference accepts structured source metadata serialized as JSON.
        // VARCHAR(255) is too small for legitimate official-source provenance.
        DB::statement('ALTER TABLE curriculum_versions ALTER COLUMN source_reference TYPE text');
    }

    public function down(): void
    {
        // PostgreSQL will refuse this rollback rather than truncate data if any
        // persisted reference no longer fits the historical 255-character limit.
        DB::statement('ALTER TABLE curriculum_versions ALTER COLUMN source_reference TYPE varchar(255)');
    }
};
