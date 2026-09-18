<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('group_subjects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->constrained('groups')->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('slug', 140);
            $table->string('color', 7)->default('#3B82F6');
            $table->string('origin', 20)->default('custom');
            $table->string('curriculum_field_code', 64)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            $table->unique(['group_id', 'slug'], 'group_subjects_group_slug_unique');
            $table->index(['group_id', 'is_active'], 'group_subjects_group_active_idx');
            $table->index(['group_id', 'curriculum_field_code'], 'group_subjects_group_field_idx');
        });

        DB::statement("ALTER TABLE group_subjects ADD CONSTRAINT group_subjects_origin_check CHECK (origin IN ('official','custom'))");
        DB::statement("ALTER TABLE group_subjects ADD CONSTRAINT group_subjects_color_check CHECK (color ~ '^#[0-9A-Fa-f]{6}$')");

        Schema::table('group_schedule_blocks', function (Blueprint $table) {
            $table->foreignId('group_subject_id')
                ->nullable()
                ->after('label')
                ->constrained('group_subjects')
                ->nullOnDelete();
            $table->string('subject_name_snapshot', 120)->nullable()->after('group_subject_id');
            $table->string('subject_color_snapshot', 7)->nullable()->after('subject_name_snapshot');
        });
    }

    public function down(): void
    {
        Schema::table('group_schedule_blocks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('group_subject_id');
            $table->dropColumn(['subject_name_snapshot', 'subject_color_snapshot']);
        });

        Schema::dropIfExists('group_subjects');
    }
};
