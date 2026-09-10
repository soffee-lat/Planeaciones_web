<?php

namespace App\Enums;

enum DocumentRenderStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Failed = 'failed';
    case Succeeded = 'succeeded';

    public function isTerminal(): bool
    {
        return $this === self::Succeeded;
    }
}
