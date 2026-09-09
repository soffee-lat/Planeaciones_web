<?php

namespace App\Enums;

enum AiExecutionStatus: string
{
    case Pending = 'pending';
    case WaitingManual = 'waiting_manual';
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Uncertain = 'uncertain';
}
