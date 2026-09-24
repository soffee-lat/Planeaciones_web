<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // Official NEM curricular content titles can legitimately exceed 255
        // characters. The importer contract already allows up to 1024.
        DB::statement('ALTER TABLE curricular_contents ALTER COLUMN title TYPE text');
    }

    public function down(): void
    {
        // PostgreSQL will refuse the rollback rather than truncate data if any
        // persisted title no longer fits the historical 255-character limit.
        DB::statement('ALTER TABLE curricular_contents ALTER COLUMN title TYPE varchar(255)');
    }
};
