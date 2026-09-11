<?php

return [
    'renewal_days' => (int) env('OPERATIONAL_NOTIFICATION_RENEWAL_DAYS', 7),
    'business_timezone' => env('BUSINESS_TIMEZONE', 'America/Mexico_City'),
    'email_lease_seconds' => (int) env('OPERATIONAL_NOTIFICATION_EMAIL_LEASE_SECONDS', 120),
    'email_retry_seconds' => (int) env('OPERATIONAL_NOTIFICATION_EMAIL_RETRY_SECONDS', 60),
];
