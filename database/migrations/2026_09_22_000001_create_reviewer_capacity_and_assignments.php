<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('reviewer_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->restrictOnDelete();
            $table->string('status', 24)->default('active');
            $table->unsignedInteger('max_load');
            $table->unsignedInteger('daily_max');
            $table->unsignedBigInteger('rate_minor')->default(0);
            $table->char('currency', 3)->default('MXN');
            $table->timestampsTz();
        });
        DB::statement("ALTER TABLE reviewer_profiles ADD CONSTRAINT reviewer_profiles_status_check CHECK (status IN ('active','inactive'))");
        DB::statement('ALTER TABLE reviewer_profiles ADD CONSTRAINT reviewer_profiles_max_load_check CHECK (max_load > 0)');
        DB::statement('ALTER TABLE reviewer_profiles ADD CONSTRAINT reviewer_profiles_daily_max_check CHECK (daily_max > 0)');
        DB::statement("ALTER TABLE reviewer_profiles ADD CONSTRAINT reviewer_profiles_currency_check CHECK (currency ~ '^[A-Z]{3}$')");

        Schema::create('reviewer_grades', function (Blueprint $table) {
            $table->unsignedBigInteger('reviewer_id');
            $table->unsignedBigInteger('grade_id');
            $table->unsignedBigInteger('curriculum_version_id');
            $table->timestampTz('created_at')->useCurrent();
            $table->primary(['reviewer_id', 'grade_id']);
            $table->index(['curriculum_version_id', 'grade_id']);
        });
        DB::statement('ALTER TABLE reviewer_grades ADD CONSTRAINT reviewer_grades_reviewer_fk FOREIGN KEY (reviewer_id) REFERENCES reviewer_profiles(user_id) ON DELETE CASCADE');
        DB::statement('ALTER TABLE reviewer_grades ADD CONSTRAINT reviewer_grades_grade_version_fk FOREIGN KEY (grade_id, curriculum_version_id) REFERENCES grades(id, curriculum_version_id) ON DELETE RESTRICT');

        Schema::create('reviewer_availability', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('reviewer_id');
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at');
            $table->timestampsTz();
            $table->index(['reviewer_id', 'starts_at', 'ends_at']);
        });
        DB::statement('ALTER TABLE reviewer_availability ADD CONSTRAINT reviewer_availability_reviewer_fk FOREIGN KEY (reviewer_id) REFERENCES reviewer_profiles(user_id) ON DELETE CASCADE');
        DB::statement('ALTER TABLE reviewer_availability ADD CONSTRAINT reviewer_availability_range_check CHECK (ends_at > starts_at)');

        Schema::create('review_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('request_id')->constrained('planning_requests')->restrictOnDelete();
            $table->unsignedBigInteger('reviewer_id');
            $table->unsignedInteger('cycle')->default(1);
            $table->string('status', 24)->default('assigned');
            $table->timestampTz('due_at')->nullable();
            $table->timestampTz('assigned_at')->useCurrent();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('ended_at')->nullable();
            $table->foreignId('ended_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('ended_reason')->nullable();
            $table->unsignedBigInteger('rate_snapshot_minor');
            $table->unsignedInteger('units_snapshot');
            $table->unsignedBigInteger('total_fee_minor');
            $table->char('currency', 3);
            $table->timestampsTz();

            $table->index(['reviewer_id', 'status', 'due_at']);
            $table->index(['request_id', 'cycle']);
            $table->index(['reviewer_id', 'assigned_at']);
        });
        DB::statement('ALTER TABLE review_assignments ADD CONSTRAINT review_assignments_reviewer_fk FOREIGN KEY (reviewer_id) REFERENCES reviewer_profiles(user_id) ON DELETE RESTRICT');
        DB::statement("ALTER TABLE review_assignments ADD CONSTRAINT review_assignments_status_check CHECK (status IN ('assigned','in_progress','completed','reassigned','cancelled'))");
        DB::statement('ALTER TABLE review_assignments ADD CONSTRAINT review_assignments_cycle_check CHECK (cycle > 0)');
        DB::statement('ALTER TABLE review_assignments ADD CONSTRAINT review_assignments_units_check CHECK (units_snapshot > 0)');
        DB::statement('ALTER TABLE review_assignments ADD CONSTRAINT review_assignments_total_fee_check CHECK (total_fee_minor = rate_snapshot_minor * units_snapshot)');
        DB::statement("ALTER TABLE review_assignments ADD CONSTRAINT review_assignments_currency_check CHECK (currency ~ '^[A-Z]{3}$')");
        DB::statement('ALTER TABLE review_assignments ADD CONSTRAINT review_assignments_started_check CHECK (started_at IS NULL OR started_at >= assigned_at)');
        DB::statement('ALTER TABLE review_assignments ADD CONSTRAINT review_assignments_ended_check CHECK (ended_at IS NULL OR ended_at >= assigned_at)');
        DB::statement("ALTER TABLE review_assignments ADD CONSTRAINT review_assignments_terminal_check CHECK ((status IN ('completed','reassigned','cancelled')) = (ended_at IS NOT NULL))");
        DB::statement("ALTER TABLE review_assignments ADD CONSTRAINT review_assignments_started_status_check CHECK (started_at IS NULL OR status IN ('in_progress','completed','reassigned','cancelled'))");
        DB::statement('ALTER TABLE review_assignments ADD CONSTRAINT review_assignments_ended_actor_check CHECK (ended_by IS NULL OR ended_at IS NOT NULL)');
        DB::statement('ALTER TABLE review_assignments ADD CONSTRAINT review_assignments_ended_reason_check CHECK (ended_reason IS NULL OR ended_at IS NOT NULL)');
        DB::statement("CREATE UNIQUE INDEX review_assignments_one_active_request_uniq ON review_assignments (request_id) WHERE status IN ('assigned','in_progress')");

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION review_assignment_insert_guard() RETURNS trigger
            LANGUAGE plpgsql AS $$
            DECLARE
                current_rate bigint;
                current_currency text;
            BEGIN
                IF NEW.status <> 'assigned' OR NEW.started_at IS NOT NULL OR NEW.ended_at IS NOT NULL
                   OR NEW.ended_by IS NOT NULL OR NEW.ended_reason IS NOT NULL THEN
                    RAISE EXCEPTION 'REVIEW_ASSIGNMENT_INVALID_INITIAL_STATE';
                END IF;
                SELECT rate_minor, currency INTO current_rate, current_currency
                FROM reviewer_profiles WHERE user_id = NEW.reviewer_id;
                IF current_rate IS NULL THEN
                    RAISE EXCEPTION 'REVIEW_ASSIGNMENT_REVIEWER_PROFILE_REQUIRED';
                END IF;
                IF NEW.rate_snapshot_minor <> current_rate OR NEW.currency <> current_currency THEN
                    RAISE EXCEPTION 'REVIEW_ASSIGNMENT_RATE_SNAPSHOT_MISMATCH';
                END IF;
                RETURN NEW;
            END;
            $$;
        SQL);
        DB::statement('CREATE TRIGGER review_assignments_insert_trg BEFORE INSERT ON review_assignments FOR EACH ROW EXECUTE FUNCTION review_assignment_insert_guard()');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION review_assignment_history_guard() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'REVIEW_ASSIGNMENT_HISTORY_IMMUTABLE';
                END IF;

                IF OLD.request_id IS DISTINCT FROM NEW.request_id
                   OR OLD.reviewer_id IS DISTINCT FROM NEW.reviewer_id
                   OR OLD.cycle IS DISTINCT FROM NEW.cycle
                   OR OLD.assigned_at IS DISTINCT FROM NEW.assigned_at
                   OR OLD.rate_snapshot_minor IS DISTINCT FROM NEW.rate_snapshot_minor
                   OR OLD.units_snapshot IS DISTINCT FROM NEW.units_snapshot
                   OR OLD.total_fee_minor IS DISTINCT FROM NEW.total_fee_minor
                   OR OLD.currency IS DISTINCT FROM NEW.currency
                   OR OLD.created_at IS DISTINCT FROM NEW.created_at THEN
                    RAISE EXCEPTION 'REVIEW_ASSIGNMENT_IDENTITY_IMMUTABLE';
                END IF;

                IF OLD.status IN ('completed','reassigned','cancelled') AND OLD IS DISTINCT FROM NEW THEN
                    RAISE EXCEPTION 'REVIEW_ASSIGNMENT_TERMINAL_IMMUTABLE';
                END IF;

                IF OLD.status = 'assigned' AND NEW.status NOT IN ('assigned','in_progress','reassigned','cancelled') THEN
                    RAISE EXCEPTION 'REVIEW_ASSIGNMENT_INVALID_TRANSITION';
                END IF;
                IF OLD.status = 'in_progress' AND NEW.status NOT IN ('in_progress','completed','reassigned','cancelled') THEN
                    RAISE EXCEPTION 'REVIEW_ASSIGNMENT_INVALID_TRANSITION';
                END IF;

                IF NEW.status = 'in_progress' AND NEW.started_at IS NULL THEN
                    RAISE EXCEPTION 'REVIEW_ASSIGNMENT_STARTED_AT_REQUIRED';
                END IF;
                IF NEW.status IN ('reassigned','cancelled') AND NEW.ended_at IS NULL THEN
                    RAISE EXCEPTION 'REVIEW_ASSIGNMENT_ENDED_AT_REQUIRED';
                END IF;

                RETURN NEW;
            END;
            $$;
        SQL);
        DB::statement('CREATE TRIGGER review_assignments_history_trg BEFORE UPDATE OR DELETE ON review_assignments FOR EACH ROW EXECUTE FUNCTION review_assignment_history_guard()');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION review_assignment_integrity_check(target_id bigint) RETURNS void
            LANGUAGE plpgsql AS $$
            DECLARE
                a review_assignments;
                r planning_requests;
                profile reviewer_profiles;
                active_units bigint;
                daily_units bigint;
            BEGIN
                SELECT * INTO a FROM review_assignments WHERE id = target_id;
                IF NOT FOUND THEN RETURN; END IF;

                SELECT * INTO r FROM planning_requests WHERE id = a.request_id FOR UPDATE;
                IF NOT FOUND THEN RAISE EXCEPTION 'REVIEW_ASSIGNMENT_REQUEST_REQUIRED'; END IF;
                IF a.status IN ('assigned','in_progress') AND r.status <> 'REVISION_HUMANA' THEN
                    RAISE EXCEPTION 'REVIEW_ASSIGNMENT_REQUEST_STATE_INVALID';
                END IF;
                IF r.human_review_required_snapshot IS DISTINCT FROM TRUE THEN
                    RAISE EXCEPTION 'REVIEW_ASSIGNMENT_HUMAN_RIGHT_REQUIRED';
                END IF;
                IF a.units_snapshot <> r.planning_units THEN
                    RAISE EXCEPTION 'REVIEW_ASSIGNMENT_UNITS_MISMATCH';
                END IF;

                SELECT * INTO profile FROM reviewer_profiles WHERE user_id = a.reviewer_id FOR UPDATE;
                IF NOT FOUND THEN
                    RAISE EXCEPTION 'REVIEW_ASSIGNMENT_REVIEWER_PROFILE_REQUIRED';
                END IF;
                IF a.status IN ('assigned','in_progress') THEN
                    IF profile.status <> 'active' THEN
                        RAISE EXCEPTION 'REVIEW_ASSIGNMENT_REVIEWER_INACTIVE';
                    END IF;
                    IF NOT EXISTS (
                        SELECT 1
                        FROM users u
                        JOIN role_user ru ON ru.user_id = u.id
                        JOIN roles ro ON ro.id = ru.role_id
                        WHERE u.id = a.reviewer_id AND u.status = 'active' AND u.email_verified_at IS NOT NULL
                          AND ro.code = 'DOCENTE_REVISOR'
                    ) THEN
                        RAISE EXCEPTION 'REVIEW_ASSIGNMENT_REVIEWER_ROLE_REQUIRED';
                    END IF;
                    IF NOT EXISTS (
                        SELECT 1 FROM reviewer_grades rg
                        WHERE rg.reviewer_id = a.reviewer_id
                          AND rg.grade_id = r.grade_id
                          AND rg.curriculum_version_id = r.curriculum_version_id
                    ) THEN
                        RAISE EXCEPTION 'REVIEW_ASSIGNMENT_GRADE_NOT_AUTHORIZED';
                    END IF;
                    IF NOT EXISTS (
                        SELECT 1 FROM reviewer_availability av
                        WHERE av.reviewer_id = a.reviewer_id
                          AND av.starts_at <= a.assigned_at
                          AND av.ends_at >= COALESCE(a.due_at, a.assigned_at)
                    ) THEN
                        RAISE EXCEPTION 'REVIEW_ASSIGNMENT_AVAILABILITY_REQUIRED';
                    END IF;
                END IF;
                IF NOT EXISTS (
                    SELECT 1 FROM usage_reservations ur
                    WHERE ur.planning_request_id = r.id
                      AND ur.subscription_period_id = r.subscription_period_id
                      AND ur.resource = 'human_review'
                      AND ur.quantity = r.planning_units
                      AND ur.operation_key = 'planning-request:' || r.id || ':human-review'
                      AND ur.status = 'consumed'
                ) THEN
                    RAISE EXCEPTION 'REVIEW_ASSIGNMENT_HUMAN_CONSUMPTION_REQUIRED';
                END IF;
                IF NOT EXISTS (
                    SELECT 1 FROM approvals ap
                    JOIN documents d ON d.request_id = r.id
                    WHERE ap.request_id = r.id AND ap.kind = 'ai'
                      AND ap.version_id = d.current_version_id
                ) THEN
                    RAISE EXCEPTION 'REVIEW_ASSIGNMENT_AI_APPROVAL_REQUIRED';
                END IF;

                IF a.status IN ('assigned','in_progress') THEN
                    SELECT COALESCE(sum(units_snapshot), 0) INTO active_units
                    FROM review_assignments
                    WHERE reviewer_id = a.reviewer_id AND status IN ('assigned','in_progress');
                    IF active_units > profile.max_load THEN
                        RAISE EXCEPTION 'REVIEW_ASSIGNMENT_MAX_LOAD_EXCEEDED';
                    END IF;
                END IF;

                SELECT COALESCE(sum(units_snapshot), 0) INTO daily_units
                FROM review_assignments
                WHERE reviewer_id = a.reviewer_id
                  AND assigned_at::date = a.assigned_at::date
                  AND (status <> 'cancelled' OR started_at IS NOT NULL);
                IF daily_units > profile.daily_max THEN
                    RAISE EXCEPTION 'REVIEW_ASSIGNMENT_DAILY_MAX_EXCEEDED';
                END IF;
            END;
            $$;
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION review_assignment_integrity_trigger() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                PERFORM review_assignment_integrity_check(CASE WHEN TG_OP = 'DELETE' THEN OLD.id ELSE NEW.id END);
                RETURN NULL;
            END;
            $$;
        SQL);
        DB::statement('CREATE CONSTRAINT TRIGGER review_assignments_integrity_trg AFTER INSERT OR UPDATE ON review_assignments DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION review_assignment_integrity_trigger()');

        // Changes to the human-review reservation must not leave an active assignment invalid.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION review_assignment_reservation_integrity_trigger() RETURNS trigger
            LANGUAGE plpgsql AS $$
            DECLARE
                target_request bigint;
                assignment_id bigint;
            BEGIN
                target_request := CASE WHEN TG_OP = 'DELETE' THEN OLD.planning_request_id ELSE NEW.planning_request_id END;
                IF target_request IS NULL THEN RETURN NULL; END IF;
                IF COALESCE(CASE WHEN TG_OP = 'DELETE' THEN OLD.resource ELSE NEW.resource END, '') <> 'human_review' THEN RETURN NULL; END IF;

                SELECT id INTO assignment_id FROM review_assignments
                WHERE request_id = target_request AND status IN ('assigned','in_progress')
                ORDER BY id DESC LIMIT 1;
                IF assignment_id IS NOT NULL THEN
                    PERFORM review_assignment_integrity_check(assignment_id);
                END IF;
                RETURN NULL;
            END;
            $$;
        SQL);
        DB::statement('CREATE CONSTRAINT TRIGGER review_assignments_reservation_integrity_trg AFTER INSERT OR UPDATE OR DELETE ON usage_reservations DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION review_assignment_reservation_integrity_trigger()');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION reviewer_related_assignment_integrity_trigger() RETURNS trigger
            LANGUAGE plpgsql AS $$
            DECLARE
                target_reviewer bigint;
                assignment_id bigint;
            BEGIN
                IF TG_TABLE_NAME = 'reviewer_profiles' THEN
                    target_reviewer := CASE WHEN TG_OP = 'DELETE' THEN OLD.user_id ELSE NEW.user_id END;
                ELSE
                    target_reviewer := CASE WHEN TG_OP = 'DELETE' THEN OLD.reviewer_id ELSE NEW.reviewer_id END;
                END IF;
                IF target_reviewer IS NULL THEN RETURN NULL; END IF;

                FOR assignment_id IN
                    SELECT id FROM review_assignments
                    WHERE reviewer_id = target_reviewer AND status IN ('assigned','in_progress')
                    ORDER BY id
                LOOP
                    PERFORM review_assignment_integrity_check(assignment_id);
                END LOOP;
                RETURN NULL;
            END;
            $$;
        SQL);
        DB::statement('CREATE CONSTRAINT TRIGGER reviewer_profiles_assignment_integrity_trg AFTER UPDATE ON reviewer_profiles DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION reviewer_related_assignment_integrity_trigger()');
        DB::statement('CREATE CONSTRAINT TRIGGER reviewer_grades_assignment_integrity_trg AFTER INSERT OR UPDATE OR DELETE ON reviewer_grades DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION reviewer_related_assignment_integrity_trigger()');
        DB::statement('CREATE CONSTRAINT TRIGGER reviewer_availability_assignment_integrity_trg AFTER INSERT OR UPDATE OR DELETE ON reviewer_availability DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION reviewer_related_assignment_integrity_trigger()');
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS reviewer_availability_assignment_integrity_trg ON reviewer_availability');
        DB::statement('DROP TRIGGER IF EXISTS reviewer_grades_assignment_integrity_trg ON reviewer_grades');
        DB::statement('DROP TRIGGER IF EXISTS reviewer_profiles_assignment_integrity_trg ON reviewer_profiles');
        DB::statement('DROP TRIGGER IF EXISTS review_assignments_reservation_integrity_trg ON usage_reservations');
        DB::statement('DROP TRIGGER IF EXISTS review_assignments_integrity_trg ON review_assignments');
        DB::statement('DROP TRIGGER IF EXISTS review_assignments_history_trg ON review_assignments');
        DB::statement('DROP TRIGGER IF EXISTS review_assignments_insert_trg ON review_assignments');
        DB::statement('DROP FUNCTION IF EXISTS reviewer_related_assignment_integrity_trigger()');
        DB::statement('DROP FUNCTION IF EXISTS review_assignment_reservation_integrity_trigger()');
        DB::statement('DROP FUNCTION IF EXISTS review_assignment_integrity_trigger()');
        DB::statement('DROP FUNCTION IF EXISTS review_assignment_integrity_check(bigint)');
        DB::statement('DROP FUNCTION IF EXISTS review_assignment_history_guard()');
        DB::statement('DROP FUNCTION IF EXISTS review_assignment_insert_guard()');
        Schema::dropIfExists('review_assignments');
        Schema::dropIfExists('reviewer_availability');
        Schema::dropIfExists('reviewer_grades');
        Schema::dropIfExists('reviewer_profiles');
    }
};
