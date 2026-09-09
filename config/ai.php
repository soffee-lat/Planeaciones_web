<?php

return [
    // Fase 4 opera primero en modo manual. El modo API se habilita solo
    // cuando exista un proveedor real verificado (Fase 7).
    'mode' => env('AI_MODE', 'manual'),

    'prompts' => [
        'generation_key' => env('AI_GENERATION_PROMPT_KEY', 'planning.generation'),
    ],

    'manual' => [
        'disk' => env('AI_MANUAL_DISK', 'private'),
        'prefix' => env('AI_MANUAL_PREFIX', 'ai/manual'),
    ],

    'outbox' => [
        'lease_seconds' => (int) env('AI_OUTBOX_LEASE_SECONDS', 120),
        'retry_seconds' => (int) env('AI_OUTBOX_RETRY_SECONDS', 30),
    ],
];
