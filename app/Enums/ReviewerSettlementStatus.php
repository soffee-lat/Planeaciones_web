<?php

namespace App\Enums;

enum ReviewerSettlementStatus: string
{
    case Draft = 'draft';
    case Approved = 'approved';
    case Paid = 'paid';

    public function isTerminal(): bool
    {
        return $this === self::Paid;
    }
}
