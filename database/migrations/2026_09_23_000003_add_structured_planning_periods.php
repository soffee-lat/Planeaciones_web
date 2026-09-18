<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('planning_requests', function (Blueprint $table): void {
            $table->string('period_type', 16)->nullable()->after('creation_mode');
            $table->string('period_key', 32)->nullable()->after('period_type');
            $table->string('integrative_project', 255)->nullable()->after('period_key');
            $table->text('integrative_project_purpose')->nullable()->after('integrative_project');
            $table->index(['group_id', 'period_type', 'starts_on', 'ends_on'], 'planning_requests_period_lookup_idx');
        });

        Schema::create('planning_request_weeks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('planning_request_id')->constrained('planning_requests')->cascadeOnDelete();
            $table->unsignedSmallInteger('sequence');
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('label', 80);
            $table->timestamps();

            $table->unique(['planning_request_id', 'sequence'], 'planning_request_weeks_request_sequence_unique');
            $table->index(['planning_request_id', 'starts_on', 'ends_on'], 'planning_request_weeks_dates_idx');
        });

        Schema::create('planning_request_week_topics', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('planning_request_week_id')->constrained('planning_request_weeks')->cascadeOnDelete();
            $table->foreignId('group_subject_id')->nullable()->constrained('group_subjects')->nullOnDelete();
            $table->string('topic', 255);
            $table->text('notes')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(1);
            $table->timestamps();

            $table->index(['planning_request_week_id', 'sort_order'], 'planning_request_week_topics_sort_idx');
            $table->index('group_subject_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('planning_request_week_topics');
        Schema::dropIfExists('planning_request_weeks');

        Schema::table('planning_requests', function (Blueprint $table): void {
            $table->dropIndex('planning_requests_period_lookup_idx');
            $table->dropColumn([
                'period_type',
                'period_key',
                'integrative_project',
                'integrative_project_purpose',
            ]);
        });
    }
};
