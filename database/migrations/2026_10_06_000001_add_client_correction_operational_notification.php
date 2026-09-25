<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::statement('ALTER TABLE operational_notification_events DROP CONSTRAINT IF EXISTS operational_notifications_type_check');
        DB::statement("ALTER TABLE operational_notification_events ADD CONSTRAINT operational_notifications_type_check CHECK (type IN ('planning.delivered','review.assigned','client_correction.requested','subscription.renewal_upcoming'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE operational_notification_events DROP CONSTRAINT IF EXISTS operational_notifications_type_check');
        DB::statement("ALTER TABLE operational_notification_events ADD CONSTRAINT operational_notifications_type_check CHECK (type IN ('planning.delivered','review.assigned','subscription.renewal_upcoming'))");
    }
};
