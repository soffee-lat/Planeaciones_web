<?php

return [
    'disk' => env('DOCUMENTS_DISK', 'private'),
    'prefix' => trim((string) env('DOCUMENTS_PREFIX', 'documents'), '/'),
    'institutional_formats_prefix' => trim((string) env('DOCUMENT_INSTITUTIONAL_FORMATS_PREFIX', 'documents/institutional-formats'), '/'),
    'institutional_source_max_bytes' => (int) env('DOCUMENT_INSTITUTIONAL_SOURCE_MAX_BYTES', 10 * 1024 * 1024),
    'format_samples_prefix' => trim((string) env('DOCUMENT_FORMAT_SAMPLES_PREFIX', 'documents/format-samples'), '/'),
    'queue' => env('DOCUMENTS_QUEUE', 'documents'),
    'renderer_version' => 'standard-v1.0.0',
    'result_retention_days' => (int) env('DOCUMENT_RESULT_RETENTION_DAYS', 365),
];
