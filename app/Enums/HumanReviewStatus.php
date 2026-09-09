<?php

namespace App\Enums;

enum HumanReviewStatus: string
{
    case InProgress = 'in_progress';
    case Approved = 'approved';

    public function isTerminal(): bool
    {
        return $this === self::Approved;
    }
}
