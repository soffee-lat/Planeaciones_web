<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::statement('ALTER TABLE curricular_contents ALTER COLUMN title TYPE TEXT');
    }

    public function down(): void
    {
        $hasLongValues = DB::table('curricular_contents')
            ->whereRaw('char_length(title) > 255')
            ->exists();

        if ($hasLongValues) {
            throw new \RuntimeException(
                'Cannot shrink curricular_contents.title to varchar(255): values longer than 255 characters exist.'
            );
        }

        DB::statement('ALTER TABLE curricular_contents ALTER COLUMN title TYPE VARCHAR(255)');
    }
};
