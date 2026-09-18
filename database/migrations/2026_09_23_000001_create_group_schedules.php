<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('group_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->constrained('groups')->cascadeOnDelete();
            $table->unsignedInteger('revision')->default(1);
            $table->string('name')->default('Horario habitual');
            $table->time('day_starts_at')->nullable();
            $table->time('day_ends_at')->nullable();
            $table->date('valid_from')->nullable();
            $table->date('valid_until')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            $table->index(['group_id', 'is_active']);
        });

        Schema::create('group_schedule_blocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_schedule_id')->constrained('group_schedules')->cascadeOnDelete();
            $table->unsignedTinyInteger('day_of_week');
            $table->unsignedInteger('sequence');
            $table->time('starts_at');
            $table->time('ends_at');
            $table->string('label', 120);
            $table->string('block_type', 32)->default('class');
            $table->string('responsibility', 32)->default('main_teacher');
            $table->boolean('include_in_planning')->default(true);
            $table->boolean('is_flexible')->default(false);
            $table->jsonb('field_codes')->default(DB::raw("'[]'::jsonb"));
            $table->text('notes')->nullable();
            $table->timestampsTz();

            $table->index(['group_schedule_id', 'day_of_week', 'sequence'], 'group_schedule_blocks_day_sequence_idx');
            $table->unique(['group_schedule_id', 'day_of_week', 'sequence'], 'group_schedule_blocks_day_sequence_unique');
        });

        DB::statement('ALTER TABLE group_schedule_blocks ADD CONSTRAINT group_schedule_blocks_day_check CHECK (day_of_week BETWEEN 1 AND 7)');
        DB::statement("ALTER TABLE group_schedule_blocks ADD CONSTRAINT group_schedule_blocks_type_check CHECK (block_type IN ('class','flexible','break','specialist','activity','unavailable'))");
        DB::statement("ALTER TABLE group_schedule_blocks ADD CONSTRAINT group_schedule_blocks_responsibility_check CHECK (responsibility IN ('main_teacher','specialist','shared','external','unassigned'))");
        DB::statement('ALTER TABLE group_schedule_blocks ADD CONSTRAINT group_schedule_blocks_time_check CHECK (ends_at > starts_at)');
    }

    public function down(): void
    {
        Schema::dropIfExists('group_schedule_blocks');
        Schema::dropIfExists('group_schedules');
    }
};
