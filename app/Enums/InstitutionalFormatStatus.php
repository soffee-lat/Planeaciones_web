<?php

namespace App\Enums;

enum InstitutionalFormatStatus: string
{
    case PendingAnalysis = 'pending_analysis';
    case Configuring = 'configuring';
    case Ready = 'ready';
    case Unsupported = 'unsupported';
    case Archived = 'archived';

    public function isUsable(): bool
    {
        return $this === self::Ready;
    }
}
