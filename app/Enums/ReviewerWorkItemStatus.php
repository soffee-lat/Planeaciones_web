<?php

namespace App\Enums;

enum ReviewerWorkItemStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Paid = 'paid';

    public function isPayable(): bool
    {
        return $this === self::Approved;
    }

    public function isTerminal(): bool
    {
        return $this === self::Paid;
    }
}
