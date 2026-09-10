<?php

namespace App\Enums;

enum CorrectionRequestStatus: string
{
    case Requested = 'requested';
    case Accepted = 'accepted';
    case Processing = 'processing';
    case Resolved = 'resolved';
    case Rejected = 'rejected';
    case Withdrawn = 'withdrawn';

    public function isOpen(): bool
    {
        return in_array($this, [self::Requested, self::Accepted, self::Processing], true);
    }

    public function isTerminal(): bool
    {
        return ! $this->isOpen();
    }
}
