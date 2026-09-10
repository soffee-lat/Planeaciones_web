<?php

namespace App\Enums;

enum FormatSampleStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';

    public function isTerminal(): bool
    {
        return $this !== self::Pending;
    }
}
