<?php

return [
    // Credencial exclusiva para cuentas ficticias de desarrollo/QA local.
    // El seeder rechaza cualquier entorno distinto de local/testing.
    'password' => env('LOCAL_DEMO_PASSWORD', 'PruebaLocal2026!'),
];
