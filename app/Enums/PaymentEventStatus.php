<?php

namespace App\Enums;

/**
 * Estados de procesamiento de un payment_event (webhook o registro manual
 * post-hoc). DATABASE.md especifica columnas `status`, `processed_at` y
 * `error_code`; se derivan estos valores para representar el flujo:
 *
 *   received   — el evento llegó y quedó almacenado (payload_hash calculado).
 *   processed  — se aplicó a la Order/Payment sin error.
 *   ignored    — duplicado o irrelevante (log-only).
 *   failed     — el procesamiento falló; error_code registra la causa.
 */
enum PaymentEventStatus: string
{
    case Received = 'received';
    case Processed = 'processed';
    case Ignored = 'ignored';
    case Failed = 'failed';
}
