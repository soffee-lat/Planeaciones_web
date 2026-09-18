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
            $table->foreignId('group_id')->unique()->constrained('groups')->cascadeOnDelete();
            $table->unsignedInteger('revision')->default(0);
            $table->string('name', 120)->default('Horario habitual');
            $table->jsonb('active_days')->default(DB::raw("'[1,2,3,4,5]'::jsonb"));
            $table->time('day_starts_at');
            $table->time('day_ends_at');
            $table->timestampsTz();
        });

        DB::statement("ALTER TABLE group_schedules ADD CONSTRAINT group_schedules_time_check CHECK (day_starts_at < day_ends_at)");
        DB::statement("ALTER TABLE group_schedules ADD CONSTRAINT group_schedules_active_days_json_check CHECK (jsonb_typeof(active_days) = 'array')");

        Schema::create('group_schedule_blocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_schedule_id')->constrained('group_schedules')->cascadeOnDelete();
            $table->unsignedSmallInteger('day_of_week');
            $table->unsignedSmallInteger('sequence');
            $table->time('starts_at');
            $table->time('ends_at');
            $table->string('block_type', 32);
            $table->string('label', 160);
            $table->string('responsibility', 32)->default('main_teacher');
            $table->boolean('include_in_planning')->default(true);
            $table->jsonb('field_codes')->default(DB::raw("'[]'::jsonb"));
            $table->text('notes')->nullable();
            $table->timestampsTz();

            $table->unique(['group_schedule_id', 'day_of_week', 'sequence'], 'group_schedule_blocks_day_sequence_unique');
            $table->index(['group_schedule_id', 'day_of_week', 'starts_at']);
        });

        DB::statement("ALTER TABLE group_schedule_blocks ADD CONSTRAINT group_schedule_blocks_day_check CHECK (day_of_week BETWEEN 1 AND 7)");
        DB::statement("ALTER TABLE group_schedule_blocks ADD CONSTRAINT group_schedule_blocks_sequence_check CHECK (sequence > 0)");
        DB::statement("ALTER TABLE group_schedule_blocks ADD CONSTRAINT group_schedule_blocks_time_check CHECK (starts_at < ends_at)");
        DB::statement("ALTER TABLE group_schedule_blocks ADD CONSTRAINT group_schedule_blocks_type_check CHECK (block_type IN ('instructional','flexible','break','external'))");
        DB::statement("ALTER TABLE group_schedule_blocks ADD CONSTRAINT group_schedule_blocks_responsibility_check CHECK (responsibility IN ('main_teacher','specialist','shared','external','unassigned'))");
        DB::statement("ALTER TABLE group_schedule_blocks ADD CONSTRAINT group_schedule_blocks_field_codes_json_check CHECK (jsonb_typeof(field_codes) = 'array')");
    }

    public function down(): void
    {
        Schema::dropIfExists('group_schedule_blocks');
        Schema::dropIfExists('group_schedules');
    }
};
