<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('reviewer_settlements', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('reviewer_id');
            $table->char('currency', 3);
            $table->string('status', 24)->default('draft');
            $table->string('reference', 255)->nullable()->unique();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('approved_at')->nullable();
            $table->foreignId('paid_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('paid_at')->nullable();
            $table->timestampsTz();
            $table->index(['reviewer_id', 'status', 'currency']);
        });
        DB::statement('ALTER TABLE reviewer_settlements ADD CONSTRAINT reviewer_settlements_reviewer_fk FOREIGN KEY (reviewer_id) REFERENCES reviewer_profiles(user_id) ON DELETE RESTRICT');
        DB::statement("ALTER TABLE reviewer_settlements ADD CONSTRAINT reviewer_settlements_status_check CHECK (status IN ('draft','approved','paid'))");
        DB::statement("ALTER TABLE reviewer_settlements ADD CONSTRAINT reviewer_settlements_currency_check CHECK (currency ~ '^[A-Z]{3}$')");
        DB::statement("ALTER TABLE reviewer_settlements ADD CONSTRAINT reviewer_settlements_state_fields_check CHECK (\n            (status = 'draft' AND approved_by IS NULL AND approved_at IS NULL AND paid_by IS NULL AND paid_at IS NULL AND reference IS NULL) OR\n            (status = 'approved' AND approved_by IS NOT NULL AND approved_at IS NOT NULL AND paid_by IS NULL AND paid_at IS NULL AND reference IS NULL) OR\n            (status = 'paid' AND approved_by IS NOT NULL AND approved_at IS NOT NULL AND paid_by IS NOT NULL AND paid_at IS NOT NULL AND reference IS NOT NULL)\n        )");
        DB::statement("CREATE UNIQUE INDEX reviewer_settlements_one_open_uniq ON reviewer_settlements (reviewer_id, currency) WHERE status IN ('draft','approved')");

        Schema::create('reviewer_work_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('request_id')->constrained('planning_requests')->restrictOnDelete();
            $table->foreignId('review_id')->unique()->constrained('reviews')->restrictOnDelete();
            $table->foreignId('assignment_id')->unique()->constrained('review_assignments')->restrictOnDelete();
            $table->unsignedBigInteger('reviewer_id');
            $table->unsignedInteger('cycle');
            $table->unsignedBigInteger('rate_minor');
            $table->unsignedInteger('quantity');
            $table->unsignedBigInteger('total_minor');
            $table->char('currency', 3);
            $table->string('status', 24)->default('approved');
            $table->timestampTz('approved_at')->nullable();
            $table->timestampTz('paid_at')->nullable();
            $table->foreignId('settlement_id')->nullable()->constrained('reviewer_settlements')->restrictOnDelete();
            $table->timestampsTz();
            $table->unique(['request_id', 'cycle'], 'reviewer_work_items_request_cycle_uniq');
            $table->index(['reviewer_id', 'status', 'currency']);
            $table->index(['settlement_id', 'status']);
        });
        DB::statement('ALTER TABLE reviewer_work_items ADD CONSTRAINT reviewer_work_items_reviewer_fk FOREIGN KEY (reviewer_id) REFERENCES reviewer_profiles(user_id) ON DELETE RESTRICT');
        DB::statement("ALTER TABLE reviewer_work_items ADD CONSTRAINT reviewer_work_items_status_check CHECK (status IN ('pending','approved','paid'))");
        DB::statement('ALTER TABLE reviewer_work_items ADD CONSTRAINT reviewer_work_items_cycle_check CHECK (cycle > 0)');
        DB::statement('ALTER TABLE reviewer_work_items ADD CONSTRAINT reviewer_work_items_quantity_check CHECK (quantity > 0)');
        DB::statement('ALTER TABLE reviewer_work_items ADD CONSTRAINT reviewer_work_items_total_check CHECK (total_minor = rate_minor * quantity)');
        DB::statement("ALTER TABLE reviewer_work_items ADD CONSTRAINT reviewer_work_items_currency_check CHECK (currency ~ '^[A-Z]{3}$')");
        DB::statement("ALTER TABLE reviewer_work_items ADD CONSTRAINT reviewer_work_items_state_fields_check CHECK (\n            (status = 'pending' AND approved_at IS NULL AND paid_at IS NULL) OR\n            (status = 'approved' AND approved_at IS NOT NULL AND paid_at IS NULL) OR\n            (status = 'paid' AND approved_at IS NOT NULL AND paid_at IS NOT NULL AND settlement_id IS NOT NULL)\n        )");

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION reviewer_work_item_history_guard() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'REVIEWER_WORK_ITEM_HISTORY_IMMUTABLE';
                END IF;

                IF OLD.request_id IS DISTINCT FROM NEW.request_id
                   OR OLD.review_id IS DISTINCT FROM NEW.review_id
                   OR OLD.assignment_id IS DISTINCT FROM NEW.assignment_id
                   OR OLD.reviewer_id IS DISTINCT FROM NEW.reviewer_id
                   OR OLD.cycle IS DISTINCT FROM NEW.cycle
                   OR OLD.rate_minor IS DISTINCT FROM NEW.rate_minor
                   OR OLD.quantity IS DISTINCT FROM NEW.quantity
                   OR OLD.total_minor IS DISTINCT FROM NEW.total_minor
                   OR OLD.currency IS DISTINCT FROM NEW.currency
                   OR OLD.approved_at IS DISTINCT FROM NEW.approved_at
                   OR OLD.created_at IS DISTINCT FROM NEW.created_at THEN
                    RAISE EXCEPTION 'REVIEWER_WORK_ITEM_IDENTITY_IMMUTABLE';
                END IF;

                IF OLD.status = 'paid' AND OLD IS DISTINCT FROM NEW THEN
                    RAISE EXCEPTION 'REVIEWER_WORK_ITEM_PAID_IMMUTABLE';
                END IF;
                IF OLD.status = 'pending' AND NEW.status NOT IN ('pending','approved') THEN
                    RAISE EXCEPTION 'REVIEWER_WORK_ITEM_INVALID_TRANSITION';
                END IF;
                IF OLD.status = 'approved' AND NEW.status NOT IN ('approved','paid') THEN
                    RAISE EXCEPTION 'REVIEWER_WORK_ITEM_INVALID_TRANSITION';
                END IF;
                IF OLD.settlement_id IS NOT NULL AND OLD.settlement_id IS DISTINCT FROM NEW.settlement_id THEN
                    RAISE EXCEPTION 'REVIEWER_WORK_ITEM_SETTLEMENT_IMMUTABLE';
                END IF;
                IF OLD.settlement_id IS NULL AND NEW.settlement_id IS NOT NULL AND OLD.status <> 'approved' THEN
                    RAISE EXCEPTION 'REVIEWER_WORK_ITEM_NOT_PAYABLE';
                END IF;

                RETURN NEW;
            END;
            $$;
        SQL);
        DB::statement('CREATE TRIGGER reviewer_work_items_history_trg BEFORE UPDATE OR DELETE ON reviewer_work_items FOR EACH ROW EXECUTE FUNCTION reviewer_work_item_history_guard()');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION reviewer_settlement_history_guard() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'REVIEWER_SETTLEMENT_HISTORY_IMMUTABLE';
                END IF;

                IF OLD.reviewer_id IS DISTINCT FROM NEW.reviewer_id
                   OR OLD.currency IS DISTINCT FROM NEW.currency
                   OR OLD.created_at IS DISTINCT FROM NEW.created_at THEN
                    RAISE EXCEPTION 'REVIEWER_SETTLEMENT_IDENTITY_IMMUTABLE';
                END IF;
                IF OLD.status = 'paid' AND OLD IS DISTINCT FROM NEW THEN
                    RAISE EXCEPTION 'REVIEWER_SETTLEMENT_PAID_IMMUTABLE';
                END IF;
                IF OLD.status = 'draft' AND NEW.status NOT IN ('draft','approved') THEN
                    RAISE EXCEPTION 'REVIEWER_SETTLEMENT_INVALID_TRANSITION';
                END IF;
                IF OLD.status = 'approved' AND NEW.status NOT IN ('approved','paid') THEN
                    RAISE EXCEPTION 'REVIEWER_SETTLEMENT_INVALID_TRANSITION';
                END IF;

                RETURN NEW;
            END;
            $$;
        SQL);
        DB::statement('CREATE TRIGGER reviewer_settlements_history_trg BEFORE UPDATE OR DELETE ON reviewer_settlements FOR EACH ROW EXECUTE FUNCTION reviewer_settlement_history_guard()');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION reviewer_work_item_integrity_check(target_id bigint) RETURNS void
            LANGUAGE plpgsql AS $$
            DECLARE
                wi reviewer_work_items;
                rv reviews;
                ra review_assignments;
                rs reviewer_settlements;
            BEGIN
                SELECT * INTO wi FROM reviewer_work_items WHERE id = target_id;
                IF NOT FOUND THEN
                    RETURN;
                END IF;

                SELECT * INTO rv FROM reviews WHERE id = wi.review_id;
                SELECT * INTO ra FROM review_assignments WHERE id = wi.assignment_id;
                IF rv.id IS NULL OR ra.id IS NULL
                   OR rv.request_id IS DISTINCT FROM wi.request_id
                   OR ra.request_id IS DISTINCT FROM wi.request_id
                   OR rv.assignment_id IS DISTINCT FROM wi.assignment_id
                   OR rv.reviewer_id IS DISTINCT FROM wi.reviewer_id
                   OR ra.reviewer_id IS DISTINCT FROM wi.reviewer_id
                   OR ra.cycle IS DISTINCT FROM wi.cycle THEN
                    RAISE EXCEPTION 'REVIEWER_WORK_ITEM_REVIEW_ASSIGNMENT_MISMATCH';
                END IF;

                IF rv.status IS DISTINCT FROM 'approved'
                   OR ra.status IS DISTINCT FROM 'completed'
                   OR ra.ended_reason IS DISTINCT FROM 'human_review_approved' THEN
                    RAISE EXCEPTION 'REVIEWER_WORK_ITEM_REVIEW_NOT_APPROVED';
                END IF;

                IF wi.rate_minor IS DISTINCT FROM ra.rate_snapshot_minor
                   OR wi.quantity IS DISTINCT FROM ra.units_snapshot
                   OR wi.total_minor IS DISTINCT FROM ra.total_fee_minor
                   OR wi.currency IS DISTINCT FROM ra.currency THEN
                    RAISE EXCEPTION 'REVIEWER_WORK_ITEM_SNAPSHOT_MISMATCH';
                END IF;

                IF NOT EXISTS (
                    SELECT 1 FROM approvals a
                    WHERE a.request_id = wi.request_id
                      AND a.version_id = rv.version_id
                      AND a.kind = 'human'
                      AND a.review_id = rv.id
                      AND a.actor_id = wi.reviewer_id
                ) THEN
                    RAISE EXCEPTION 'REVIEWER_WORK_ITEM_HUMAN_APPROVAL_REQUIRED';
                END IF;

                IF wi.settlement_id IS NOT NULL THEN
                    SELECT * INTO rs FROM reviewer_settlements WHERE id = wi.settlement_id;
                    IF rs.id IS NULL OR rs.reviewer_id IS DISTINCT FROM wi.reviewer_id OR rs.currency IS DISTINCT FROM wi.currency THEN
                        RAISE EXCEPTION 'REVIEWER_WORK_ITEM_SETTLEMENT_MISMATCH';
                    END IF;
                    IF wi.status = 'paid' THEN
                        IF rs.status IS DISTINCT FROM 'paid' OR rs.paid_at IS DISTINCT FROM wi.paid_at THEN
                            RAISE EXCEPTION 'REVIEWER_WORK_ITEM_PAYMENT_MISMATCH';
                        END IF;
                    ELSIF rs.status = 'paid' THEN
                        RAISE EXCEPTION 'REVIEWER_WORK_ITEM_PAYMENT_MISMATCH';
                    END IF;
                ELSIF wi.status = 'paid' THEN
                    RAISE EXCEPTION 'REVIEWER_WORK_ITEM_SETTLEMENT_REQUIRED';
                END IF;
            END;
            $$;

            CREATE OR REPLACE FUNCTION reviewer_work_item_integrity_trigger() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                PERFORM reviewer_work_item_integrity_check(COALESCE(NEW.id, OLD.id));
                RETURN COALESCE(NEW, OLD);
            END;
            $$;
        SQL);
        DB::statement('CREATE CONSTRAINT TRIGGER reviewer_work_items_integrity_trg AFTER INSERT OR UPDATE ON reviewer_work_items DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION reviewer_work_item_integrity_trigger()');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION reviewer_settlement_integrity_check(target_id bigint) RETURNS void
            LANGUAGE plpgsql AS $$
            DECLARE
                rs reviewer_settlements;
                item_count bigint;
                wrong_count bigint;
            BEGIN
                SELECT * INTO rs FROM reviewer_settlements WHERE id = target_id;
                IF NOT FOUND THEN
                    RETURN;
                END IF;

                SELECT COUNT(*) INTO item_count FROM reviewer_work_items WHERE settlement_id = rs.id;
                IF item_count = 0 THEN
                    RAISE EXCEPTION 'REVIEWER_SETTLEMENT_ITEMS_REQUIRED';
                END IF;

                SELECT COUNT(*) INTO wrong_count
                FROM reviewer_work_items wi
                WHERE wi.settlement_id = rs.id
                  AND (wi.reviewer_id IS DISTINCT FROM rs.reviewer_id OR wi.currency IS DISTINCT FROM rs.currency);
                IF wrong_count > 0 THEN
                    RAISE EXCEPTION 'REVIEWER_SETTLEMENT_ITEM_MISMATCH';
                END IF;

                IF rs.status IN ('draft','approved') THEN
                    SELECT COUNT(*) INTO wrong_count
                    FROM reviewer_work_items wi
                    WHERE wi.settlement_id = rs.id AND wi.status <> 'approved';
                    IF wrong_count > 0 THEN
                        RAISE EXCEPTION 'REVIEWER_SETTLEMENT_ITEMS_NOT_APPROVED';
                    END IF;
                ELSIF rs.status = 'paid' THEN
                    SELECT COUNT(*) INTO wrong_count
                    FROM reviewer_work_items wi
                    WHERE wi.settlement_id = rs.id
                      AND (wi.status <> 'paid' OR wi.paid_at IS DISTINCT FROM rs.paid_at);
                    IF wrong_count > 0 THEN
                        RAISE EXCEPTION 'REVIEWER_SETTLEMENT_ITEMS_NOT_PAID';
                    END IF;
                END IF;
            END;
            $$;

            CREATE OR REPLACE FUNCTION reviewer_settlement_integrity_trigger() RETURNS trigger
            LANGUAGE plpgsql AS $$
            DECLARE
                target_id bigint;
            BEGIN
                IF TG_TABLE_NAME = 'reviewer_settlements' THEN
                    target_id := COALESCE(NEW.id, OLD.id);
                ELSE
                    target_id := COALESCE(NEW.settlement_id, OLD.settlement_id);
                END IF;
                IF target_id IS NOT NULL THEN
                    PERFORM reviewer_settlement_integrity_check(target_id);
                END IF;
                RETURN COALESCE(NEW, OLD);
            END;
            $$;
        SQL);
        DB::statement('CREATE CONSTRAINT TRIGGER reviewer_settlements_integrity_trg AFTER INSERT OR UPDATE ON reviewer_settlements DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION reviewer_settlement_integrity_trigger()');
        DB::statement('CREATE CONSTRAINT TRIGGER reviewer_work_items_settlement_integrity_trg AFTER INSERT OR UPDATE ON reviewer_work_items DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION reviewer_settlement_integrity_trigger()');
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS reviewer_work_items_settlement_integrity_trg ON reviewer_work_items');
        DB::statement('DROP TRIGGER IF EXISTS reviewer_settlements_integrity_trg ON reviewer_settlements');
        DB::statement('DROP TRIGGER IF EXISTS reviewer_work_items_integrity_trg ON reviewer_work_items');
        DB::statement('DROP TRIGGER IF EXISTS reviewer_settlements_history_trg ON reviewer_settlements');
        DB::statement('DROP TRIGGER IF EXISTS reviewer_work_items_history_trg ON reviewer_work_items');
        DB::statement('DROP FUNCTION IF EXISTS reviewer_settlement_integrity_trigger()');
        DB::statement('DROP FUNCTION IF EXISTS reviewer_settlement_integrity_check(bigint)');
        DB::statement('DROP FUNCTION IF EXISTS reviewer_work_item_integrity_trigger()');
        DB::statement('DROP FUNCTION IF EXISTS reviewer_work_item_integrity_check(bigint)');
        DB::statement('DROP FUNCTION IF EXISTS reviewer_settlement_history_guard()');
        DB::statement('DROP FUNCTION IF EXISTS reviewer_work_item_history_guard()');
        Schema::dropIfExists('reviewer_work_items');
        Schema::dropIfExists('reviewer_settlements');
    }
};
