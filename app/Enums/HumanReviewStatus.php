<?php

namespace App\Enums;

enum HumanReviewStatus: string
{
    case InProgress = 'in_progress';
    case Approved = 'approved';
    case ChangesRequested = 'changes_requested';
    case Escalated = 'escalated';
    case Rejected = 'rejected';

    public function isTerminal(): bool
    {
        return $this !== self::InProgress;
    }
}
