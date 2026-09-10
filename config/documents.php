<?php

return [
    'disk' => env('DOCUMENTS_DISK', 'private'),
    'prefix' => trim((string) env('DOCUMENTS_PREFIX', 'documents'), '/'),
    'format_samples_prefix' => trim((string) env('DOCUMENT_FORMAT_SAMPLES_PREFIX', 'documents/format-samples'), '/'),
    'queue' => env('DOCUMENTS_QUEUE', 'documents'),
    'renderer_version' => 'standard-v1.0.0',
    'result_retention_days' => (int) env('DOCUMENT_RESULT_RETENTION_DAYS', 365),
];
