<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('planning_requests', function (Blueprint $table) {
            $table->foreignId('subscription_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('subscription_period_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('plan_version_id')->nullable()->constrained()->restrictOnDelete();
            $table->integer('planning_days')->nullable();
            $table->integer('planning_units')->nullable();
            $table->string('calculation_strategy', 64)->nullable();
            $table->jsonb('calculation_snapshot')->nullable();
            $table->integer('correction_limit_snapshot')->nullable();
            $table->boolean('human_review_required_snapshot')->nullable();
            $table->timestampTz('commercial_authorized_at')->nullable();
        });
        DB::statement('CREATE UNIQUE INDEX subscriptions_id_customer_uniq ON subscriptions(id, customer_id)');
        DB::statement('CREATE UNIQUE INDEX periods_commercial_identity_uniq ON subscription_periods(id, subscription_id, plan_version_id)');
        DB::statement('ALTER TABLE planning_requests ADD CONSTRAINT planning_commercial_owner_fk FOREIGN KEY (subscription_id, owner_id) REFERENCES subscriptions(id, customer_id)');
        DB::statement('ALTER TABLE planning_requests ADD CONSTRAINT planning_commercial_period_fk FOREIGN KEY (subscription_period_id, subscription_id, plan_version_id) REFERENCES subscription_periods(id, subscription_id, plan_version_id)');
        DB::statement(<<<'SQL'
            ALTER TABLE planning_requests ADD CONSTRAINT planning_commercial_complete CHECK (
                (commercial_authorized_at IS NULL AND num_nonnulls(subscription_id, subscription_period_id, plan_version_id, planning_days, planning_units, calculation_strategy, calculation_snapshot, correction_limit_snapshot, human_review_required_snapshot) = 0)
                OR (commercial_authorized_at IS NOT NULL AND num_nonnulls(subscription_id, subscription_period_id, plan_version_id, planning_days, planning_units, calculation_strategy, calculation_snapshot, correction_limit_snapshot, human_review_required_snapshot) = 9
                    AND planning_days > 0 AND planning_units > 0 AND correction_limit_snapshot >= 0
                    AND calculation_strategy = 'calendar_days_v1' AND jsonb_typeof(calculation_snapshot) = 'object')
            )
        SQL);
        Schema::create('planning_request_segments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('planning_request_id')->constrained()->restrictOnDelete();
            $table->integer('sequence');
            $table->date('starts_on');
            $table->date('ends_on');
            $table->integer('calendar_days');
            $table->integer('units')->default(1);
            $table->timestampsTz();
            $table->unique(['planning_request_id', 'sequence']);
        });
        DB::statement('ALTER TABLE planning_request_segments ADD CONSTRAINT planning_segments_dates CHECK (ends_on >= starts_on AND calendar_days = ends_on - starts_on + 1 AND sequence > 0 AND units = 1)');
        DB::statement("ALTER TABLE planning_request_segments ADD CONSTRAINT planning_segments_no_overlap EXCLUDE USING gist (planning_request_id WITH =, daterange(starts_on, ends_on, '[]') WITH &&)");
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION planning_segment_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE parent planning_requests; target_id bigint;
            BEGIN
                target_id := CASE WHEN TG_OP = 'DELETE' THEN OLD.planning_request_id ELSE NEW.planning_request_id END;
                IF TG_OP = 'UPDATE' AND NEW.planning_request_id <> OLD.planning_request_id THEN
                    RAISE EXCEPTION 'PLANNING_SEGMENT_PARENT_IMMUTABLE';
                END IF;
                SELECT * INTO parent FROM planning_requests WHERE id = target_id FOR UPDATE;
                IF parent.commercial_authorized_at IS NOT NULL THEN
                    RAISE EXCEPTION 'PLANNING_SEGMENTS_IMMUTABLE';
                END IF;
                IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
                IF parent.status <> 'ESPERANDO_PAGO' OR NEW.starts_on < parent.starts_on OR NEW.ends_on > parent.ends_on THEN
                    RAISE EXCEPTION 'PLANNING_SEGMENT_INVALID_RANGE';
                END IF;
                RETURN NEW;
            END; $$;
            CREATE TRIGGER planning_segment_guard_trg BEFORE INSERT OR UPDATE OR DELETE ON planning_request_segments
                FOR EACH ROW EXECUTE FUNCTION planning_segment_guard();

            CREATE OR REPLACE FUNCTION planning_commercial_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE segments jsonb; max_days integer;
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    IF OLD.commercial_authorized_at IS NOT NULL THEN RAISE EXCEPTION 'PLANNING_COMMERCIAL_IMMUTABLE'; END IF;
                    RETURN OLD;
                END IF;
                IF OLD.commercial_authorized_at IS NOT NULL AND
                    ROW(NEW.subscription_id, NEW.subscription_period_id, NEW.plan_version_id, NEW.planning_days, NEW.planning_units, NEW.calculation_strategy, NEW.calculation_snapshot, NEW.correction_limit_snapshot, NEW.human_review_required_snapshot, NEW.commercial_authorized_at, NEW.starts_on, NEW.ends_on, NEW.owner_id, NEW.group_id)
                    IS DISTINCT FROM ROW(OLD.subscription_id, OLD.subscription_period_id, OLD.plan_version_id, OLD.planning_days, OLD.planning_units, OLD.calculation_strategy, OLD.calculation_snapshot, OLD.correction_limit_snapshot, OLD.human_review_required_snapshot, OLD.commercial_authorized_at, OLD.starts_on, OLD.ends_on, OLD.owner_id, OLD.group_id) THEN
                    RAISE EXCEPTION 'PLANNING_COMMERCIAL_IMMUTABLE';
                END IF;
                IF OLD.commercial_authorized_at IS NULL AND NEW.commercial_authorized_at IS NOT NULL THEN
                    IF OLD.status <> 'ESPERANDO_PAGO' OR NEW.status <> 'LISTA_PARA_PROCESAR' OR NEW.input_snapshot IS NULL OR NEW.current_version_id IS NULL THEN
                        RAISE EXCEPTION 'PLANNING_COMMERCIAL_INVALID_STATE';
                    END IF;
                    SELECT max_planning_days INTO max_days FROM plan_versions WHERE id = NEW.plan_version_id;
                    SELECT jsonb_agg(jsonb_build_object('sequence', sequence, 'starts_on', starts_on, 'ends_on', ends_on, 'calendar_days', calendar_days, 'units', units) ORDER BY sequence)
                        INTO segments FROM planning_request_segments WHERE planning_request_id = NEW.id;
                    IF segments IS NULL OR segments IS DISTINCT FROM NEW.calculation_snapshot->'segments'
                        OR NEW.planning_days <> NEW.ends_on - NEW.starts_on + 1
                        OR NEW.planning_units <> ceil(NEW.planning_days::numeric / max_days)
                        OR (SELECT count(*) FROM planning_request_segments WHERE planning_request_id = NEW.id) <> NEW.planning_units
                        OR (SELECT sum(calendar_days) FROM planning_request_segments WHERE planning_request_id = NEW.id) <> NEW.planning_days
                        OR EXISTS (SELECT 1 FROM planning_request_segments WHERE planning_request_id = NEW.id AND
                            (calendar_days > max_days OR starts_on <> NEW.starts_on + (sequence - 1) * max_days
                             OR ends_on <> LEAST(NEW.ends_on, NEW.starts_on + sequence * max_days - 1))) THEN
                        RAISE EXCEPTION 'PLANNING_COMMERCIAL_INVALID_SEGMENTS';
                    END IF;
                END IF;
                RETURN NEW;
            END; $$;
            CREATE TRIGGER planning_commercial_guard_trg BEFORE UPDATE OR DELETE ON planning_requests
                FOR EACH ROW EXECUTE FUNCTION planning_commercial_guard();
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER planning_commercial_guard_trg ON planning_requests');
        DB::statement('DROP FUNCTION planning_commercial_guard()');
        Schema::dropIfExists('planning_request_segments');
        DB::statement('DROP FUNCTION planning_segment_guard()');
        DB::statement('ALTER TABLE planning_requests DROP CONSTRAINT planning_commercial_complete, DROP CONSTRAINT planning_commercial_period_fk, DROP CONSTRAINT planning_commercial_owner_fk');
        Schema::table('planning_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('subscription_id');
            $table->dropConstrainedForeignId('subscription_period_id');
            $table->dropConstrainedForeignId('plan_version_id');
            $table->dropColumn(['planning_days', 'planning_units', 'calculation_strategy', 'calculation_snapshot', 'correction_limit_snapshot', 'human_review_required_snapshot', 'commercial_authorized_at']);
        });
        DB::statement('DROP INDEX periods_commercial_identity_uniq');
        DB::statement('DROP INDEX subscriptions_id_customer_uniq');
    }
};
