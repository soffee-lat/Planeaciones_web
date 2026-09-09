<?php

namespace App\Enums;

enum OutboxEventType: string
{
    case PlanningGenerationRequested = 'planning.generation.requested';
    case PlanningAuditRequested = 'planning.audit.requested';
}
