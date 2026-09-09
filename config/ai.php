<?php

return [
    // Fase 4 opera primero en modo manual. El modo API se habilita solo
    // cuando exista un proveedor real verificado (Fase 7).
    'mode' => env('AI_MODE', 'manual'),

    'prompts' => [
        'generation_key' => env('AI_GENERATION_PROMPT_KEY', 'planning.generation'),
        'audit_key' => env('AI_AUDIT_PROMPT_KEY', 'planning.audit'),
        'correction_key' => env('AI_CORRECTION_PROMPT_KEY', 'planning.correction'),
    ],

    'manual' => [
        'disk' => env('AI_MANUAL_DISK', 'private'),
        'prefix' => env('AI_MANUAL_PREFIX', 'ai/manual'),
    ],

    'internal_correction' => [
        'max_rounds' => (int) env('AI_INTERNAL_CORRECTION_MAX_ROUNDS', 2),
        'max_known_cost' => env('AI_INTERNAL_CORRECTION_MAX_KNOWN_COST'),
        'cost_currency' => env('AI_INTERNAL_CORRECTION_COST_CURRENCY'),
    ],

    'outbox' => [
        'lease_seconds' => (int) env('AI_OUTBOX_LEASE_SECONDS', 120),
        'retry_seconds' => (int) env('AI_OUTBOX_RETRY_SECONDS', 30),
    ],
];
