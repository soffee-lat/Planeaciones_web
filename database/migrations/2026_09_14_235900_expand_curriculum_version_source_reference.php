<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::statement('ALTER TABLE curriculum_versions ALTER COLUMN source_reference TYPE TEXT');
    }

    public function down(): void
    {
        $hasLongValues = DB::table('curriculum_versions')
            ->whereRaw('char_length(source_reference) > 255')
            ->exists();

        if ($hasLongValues) {
            throw new \RuntimeException(
                'Cannot shrink curriculum_versions.source_reference to varchar(255): values longer than 255 characters exist.'
            );
        }

        DB::statement('ALTER TABLE curriculum_versions ALTER COLUMN source_reference TYPE VARCHAR(255)');
    }
};
