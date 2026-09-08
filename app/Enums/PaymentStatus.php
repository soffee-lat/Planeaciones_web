<?php

namespace App\Enums;

/**
 * DATABASE.md § payments (verbatim):
 *   status (pending/succeeded/failed/cancelled)
 */
enum PaymentStatus: string
{
    case Pending = 'pending';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
}
