<?php

namespace App\Enums;

enum ReviewAssignmentStatus: string
{
    case Assigned = 'assigned';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Reassigned = 'reassigned';
    case Cancelled = 'cancelled';

    public function isActive(): bool
    {
        return in_array($this, [self::Assigned, self::InProgress], true);
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Completed, self::Reassigned, self::Cancelled], true);
    }
}
