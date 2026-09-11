<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('planning_requests', function (Blueprint $table): void {
            $table->char('curriculum_selection_fingerprint', 64)
                ->nullable()
                ->after('curriculum_confirmed_at');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE planning_requests
            ADD CONSTRAINT planning_requests_curriculum_fingerprint_check
            CHECK (
                curriculum_selection_fingerprint IS NULL
                OR curriculum_selection_fingerprint ~ '^[0-9a-f]{64}$'
            )
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE planning_requests DROP CONSTRAINT IF EXISTS planning_requests_curriculum_fingerprint_check');

        Schema::table('planning_requests', function (Blueprint $table): void {
            $table->dropColumn('curriculum_selection_fingerprint');
        });
    }
};
