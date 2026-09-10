<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestampTz('read_at')->nullable();
            $table->timestampsTz();
        });

        Schema::create('operational_notification_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_key', 255)->unique();
            $table->string('type', 64);
            $table->unsignedBigInteger('recipient_id');
            $table->string('aggregate_type', 64);
            $table->unsignedBigInteger('aggregate_id');
            $table->jsonb('payload');
            $table->timestampTz('internal_sent_at')->nullable();
            $table->timestampTz('email_sent_at')->nullable();
            $table->unsignedInteger('email_attempts')->default(0);
            $table->timestampTz('email_available_at')->useCurrent();
            $table->timestampTz('email_claimed_at')->nullable();
            $table->timestampTz('email_lease_expires_at')->nullable();
            $table->string('email_last_error_code', 128)->nullable();
            $table->timestampsTz();
            $table->index(['recipient_id', 'created_at']);
            $table->index(['email_sent_at', 'email_available_at'], 'operational_notifications_email_pending_idx');
        });

        DB::statement('ALTER TABLE operational_notification_events ADD CONSTRAINT operational_notifications_recipient_fk FOREIGN KEY (recipient_id) REFERENCES users(id) ON DELETE RESTRICT');
        DB::statement("ALTER TABLE operational_notification_events ADD CONSTRAINT operational_notifications_type_check CHECK (type IN ('planning.delivered','review.assigned','subscription.renewal_upcoming'))");
        DB::statement("ALTER TABLE operational_notification_events ADD CONSTRAINT operational_notifications_event_key_check CHECK (length(trim(event_key)) > 0)");
        DB::statement("ALTER TABLE operational_notification_events ADD CONSTRAINT operational_notifications_aggregate_check CHECK (length(trim(aggregate_type)) > 0 AND aggregate_id > 0)");
        DB::statement("ALTER TABLE operational_notification_events ADD CONSTRAINT operational_notifications_payload_check CHECK (jsonb_typeof(payload) = 'object' AND length(trim(COALESCE(payload->>'title',''))) > 0 AND length(trim(COALESCE(payload->>'body',''))) > 0)");
        DB::statement("ALTER TABLE operational_notification_events ADD CONSTRAINT operational_notifications_claim_check CHECK ((email_claimed_at IS NULL AND email_lease_expires_at IS NULL) OR (email_claimed_at IS NOT NULL AND email_lease_expires_at IS NOT NULL AND email_lease_expires_at > email_claimed_at))");

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION operational_notification_history_guard() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'OPERATIONAL_NOTIFICATION_HISTORY_IMMUTABLE';
    END IF;
    IF OLD.event_key IS DISTINCT FROM NEW.event_key
       OR OLD.type IS DISTINCT FROM NEW.type
       OR OLD.recipient_id IS DISTINCT FROM NEW.recipient_id
       OR OLD.aggregate_type IS DISTINCT FROM NEW.aggregate_type
       OR OLD.aggregate_id IS DISTINCT FROM NEW.aggregate_id
       OR OLD.payload IS DISTINCT FROM NEW.payload
       OR OLD.created_at IS DISTINCT FROM NEW.created_at THEN
        RAISE EXCEPTION 'OPERATIONAL_NOTIFICATION_IDENTITY_IMMUTABLE';
    END IF;
    IF OLD.internal_sent_at IS NOT NULL AND OLD.internal_sent_at IS DISTINCT FROM NEW.internal_sent_at THEN
        RAISE EXCEPTION 'OPERATIONAL_NOTIFICATION_INTERNAL_TERMINAL';
    END IF;
    IF OLD.email_sent_at IS NOT NULL AND OLD.email_sent_at IS DISTINCT FROM NEW.email_sent_at THEN
        RAISE EXCEPTION 'OPERATIONAL_NOTIFICATION_EMAIL_TERMINAL';
    END IF;
    IF NEW.email_attempts < OLD.email_attempts THEN
        RAISE EXCEPTION 'OPERATIONAL_NOTIFICATION_ATTEMPTS_MONOTONIC';
    END IF;
    RETURN NEW;
END; $$;
SQL);
        DB::statement('CREATE TRIGGER operational_notification_history_trg BEFORE UPDATE OR DELETE ON operational_notification_events FOR EACH ROW EXECUTE FUNCTION operational_notification_history_guard()');
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS operational_notification_history_trg ON operational_notification_events');
        DB::statement('DROP FUNCTION IF EXISTS operational_notification_history_guard()');
        Schema::dropIfExists('operational_notification_events');
        Schema::dropIfExists('notifications');
    }
};
