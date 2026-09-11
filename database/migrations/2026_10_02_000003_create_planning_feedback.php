<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('planning_feedback', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('planning_request_id')->unique()->constrained('planning_requests')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('delivery_id')->constrained('deliveries')->restrictOnDelete();
            $table->string('saved_time_bucket', 32);
            $table->string('most_helpful', 64);
            $table->string('next_real_planning', 16);
            $table->timestampTz('submitted_at')->useCurrent();

            $table->index(['user_id', 'submitted_at']);
        });

        DB::statement("ALTER TABLE planning_feedback ADD CONSTRAINT planning_feedback_saved_time_check CHECK (saved_time_bucket IN ('none','under_30','30_60','60_120','over_120'))");
        DB::statement("ALTER TABLE planning_feedback ADD CONSTRAINT planning_feedback_helpful_check CHECK (most_helpful IN ('curriculum','connections','projects_materials','activities','assessment','group_adaptation','institutional_format','other'))");
        DB::statement("ALTER TABLE planning_feedback ADD CONSTRAINT planning_feedback_next_check CHECK (next_real_planning IN ('yes','maybe','no'))");

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION planning_feedback_integrity_guard() RETURNS trigger
LANGUAGE plpgsql AS $$
DECLARE
    req planning_requests;
    del deliveries;
BEGIN
    SELECT * INTO req FROM planning_requests WHERE id = NEW.planning_request_id;
    IF NOT FOUND THEN
        RAISE EXCEPTION 'PLANNING_FEEDBACK_REQUEST_REQUIRED';
    END IF;

    SELECT * INTO del FROM deliveries WHERE id = NEW.delivery_id;
    IF NOT FOUND THEN
        RAISE EXCEPTION 'PLANNING_FEEDBACK_DELIVERY_REQUIRED';
    END IF;

    IF req.owner_id IS DISTINCT FROM NEW.user_id
       OR del.request_id IS DISTINCT FROM NEW.planning_request_id THEN
        RAISE EXCEPTION 'PLANNING_FEEDBACK_CONTEXT_MISMATCH';
    END IF;

    IF req.creation_mode IS DISTINCT FROM 'quick' THEN
        RAISE EXCEPTION 'PLANNING_FEEDBACK_VALIDATION_FLOW_REQUIRED';
    END IF;

    RETURN NEW;
END; $$;
SQL);

        DB::statement('CREATE TRIGGER planning_feedback_integrity_trg BEFORE INSERT ON planning_feedback FOR EACH ROW EXECUTE FUNCTION planning_feedback_integrity_guard()');

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION planning_feedback_immutable_guard() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
    RAISE EXCEPTION 'PLANNING_FEEDBACK_IMMUTABLE';
END; $$;
SQL);

        DB::statement('CREATE TRIGGER planning_feedback_immutable_trg BEFORE UPDATE OR DELETE ON planning_feedback FOR EACH ROW EXECUTE FUNCTION planning_feedback_immutable_guard()');
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS planning_feedback_immutable_trg ON planning_feedback');
        DB::statement('DROP FUNCTION IF EXISTS planning_feedback_immutable_guard()');
        DB::statement('DROP TRIGGER IF EXISTS planning_feedback_integrity_trg ON planning_feedback');
        DB::statement('DROP FUNCTION IF EXISTS planning_feedback_integrity_guard()');
        Schema::dropIfExists('planning_feedback');
    }
};
