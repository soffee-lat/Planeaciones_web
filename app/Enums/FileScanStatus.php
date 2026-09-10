<?php

namespace App\Enums;

enum FileScanStatus: string
{
    case Pending = 'pending';
    case Clean = 'clean';
    case Quarantined = 'quarantined';
    case Failed = 'failed';

    public function isUsable(): bool
    {
        return $this === self::Clean;
    }
}
