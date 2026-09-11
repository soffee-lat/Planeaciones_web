<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('product_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('planning_request_id')->nullable()->constrained('planning_requests')->restrictOnDelete();
            $table->foreignId('group_id')->nullable()->constrained('groups')->restrictOnDelete();
            $table->foreignId('curriculum_version_id')->nullable()->constrained('curriculum_versions')->restrictOnDelete();
            $table->foreignId('grade_id')->nullable()->constrained('grades')->restrictOnDelete();
            $table->string('event_type', 64);
            $table->jsonb('metadata');
            $table->timestampTz('occurred_at')->useCurrent();

            $table->index(['user_id', 'occurred_at']);
            $table->index(['planning_request_id', 'occurred_at']);
            $table->index(['event_type', 'occurred_at']);
        });

        DB::statement("ALTER TABLE product_events ADD CONSTRAINT product_events_metadata_object_check CHECK (jsonb_typeof(metadata) = 'object')");
        DB::statement("ALTER TABLE product_events ADD CONSTRAINT product_events_type_check CHECK (event_type IN ('planning_started','curriculum_suggestions_shown','curriculum_suggestion_accepted','curriculum_suggestion_rejected','curriculum_selection_added','curriculum_map_confirmed','plan_generated','plan_section_edited','plan_section_regenerated','plan_section_deleted','format_sample_generated','docx_downloaded','planning_completed'))");

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION product_event_integrity_guard() RETURNS trigger
LANGUAGE plpgsql AS $$
DECLARE
    req planning_requests;
    grp groups;
BEGIN
    IF NEW.planning_request_id IS NOT NULL THEN
        SELECT * INTO req FROM planning_requests WHERE id = NEW.planning_request_id;
        IF NOT FOUND THEN
            RAISE EXCEPTION 'PRODUCT_EVENT_REQUEST_REQUIRED';
        END IF;
        IF req.owner_id IS DISTINCT FROM NEW.user_id
           OR req.group_id IS DISTINCT FROM NEW.group_id
           OR req.curriculum_version_id IS DISTINCT FROM NEW.curriculum_version_id
           OR req.grade_id IS DISTINCT FROM NEW.grade_id THEN
            RAISE EXCEPTION 'PRODUCT_EVENT_REQUEST_CONTEXT_MISMATCH';
        END IF;
    ELSIF NEW.group_id IS NOT NULL THEN
        SELECT * INTO grp FROM groups WHERE id = NEW.group_id;
        IF NOT FOUND THEN
            RAISE EXCEPTION 'PRODUCT_EVENT_GROUP_REQUIRED';
        END IF;
        IF grp.owner_id IS DISTINCT FROM NEW.user_id
           OR grp.curriculum_version_id IS DISTINCT FROM NEW.curriculum_version_id
           OR grp.grade_id IS DISTINCT FROM NEW.grade_id THEN
            RAISE EXCEPTION 'PRODUCT_EVENT_GROUP_CONTEXT_MISMATCH';
        END IF;
    ELSIF NEW.curriculum_version_id IS NOT NULL OR NEW.grade_id IS NOT NULL THEN
        RAISE EXCEPTION 'PRODUCT_EVENT_ORPHAN_CURRICULUM_CONTEXT';
    END IF;

    RETURN NEW;
END; $$;
SQL);

        DB::statement('CREATE TRIGGER product_events_integrity_trg BEFORE INSERT ON product_events FOR EACH ROW EXECUTE FUNCTION product_event_integrity_guard()');

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION product_event_immutable_guard() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
    RAISE EXCEPTION 'PRODUCT_EVENT_IMMUTABLE';
END; $$;
SQL);

        DB::statement('CREATE TRIGGER product_events_immutable_trg BEFORE UPDATE OR DELETE ON product_events FOR EACH ROW EXECUTE FUNCTION product_event_immutable_guard()');
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS product_events_immutable_trg ON product_events');
        DB::statement('DROP FUNCTION IF EXISTS product_event_immutable_guard()');
        DB::statement('DROP TRIGGER IF EXISTS product_events_integrity_trg ON product_events');
        DB::statement('DROP FUNCTION IF EXISTS product_event_integrity_guard()');
        Schema::dropIfExists('product_events');
    }
};
