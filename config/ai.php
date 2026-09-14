<?php

return [
    // Fase 4 opera primero en modo manual. Fase 7 habilita `api` sólo cuando
    // exista un proveedor configurado y el borde del proveedor pase sus tests.
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

    'api' => [
        'provider' => env('AI_API_PROVIDER', 'openai'),
        'openai' => [
            'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
            'api_key' => env('OPENAI_API_KEY'),
            // Luna es el default del piloto por costo. El modelo sigue siendo
            // configuración operativa y puede cambiarse sin alterar contratos.
            'model' => env('OPENAI_MODEL', 'gpt-5.6-luna'),
            'timeout_seconds' => (int) env('OPENAI_TIMEOUT_SECONDS', 90),
            'connect_timeout_seconds' => (int) env('OPENAI_CONNECT_TIMEOUT_SECONDS', 10),
            'max_output_tokens' => (int) env('OPENAI_MAX_OUTPUT_TOKENS', 12000),
            'reasoning_effort' => env('OPENAI_REASONING_EFFORT', 'low'),
            // Nuestros schemas internos se validan localmente y algunos tienen
            // propiedades opcionales. No activar strict hasta normalizar ese
            // subconjunto de JSON Schema específicamente para el proveedor.
            'strict_schema' => (bool) env('OPENAI_STRICT_SCHEMA', false),
        ],
    ],

    'internal_correction' => [
        'max_rounds' => (int) env('AI_INTERNAL_CORRECTION_MAX_ROUNDS', 2),
        'max_known_cost' => env('AI_INTERNAL_CORRECTION_MAX_KNOWN_COST'),
        'cost_currency' => env('AI_INTERNAL_CORRECTION_COST_CURRENCY'),
    ],

    'human_review_correction' => [
        'max_rounds' => (int) env('AI_HUMAN_REVIEW_CORRECTION_MAX_ROUNDS', 3),
    ],

    'outbox' => [
        'lease_seconds' => (int) env('AI_OUTBOX_LEASE_SECONDS', 120),
        'retry_seconds' => (int) env('AI_OUTBOX_RETRY_SECONDS', 30),
    ],
];
