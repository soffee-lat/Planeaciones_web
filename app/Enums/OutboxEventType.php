<?php

namespace App\Enums;

enum OutboxEventType: string
{
    case PlanningGenerationRequested = 'planning.generation.requested';
}
