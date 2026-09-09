<?php

namespace App\Contracts\AI;

use App\Data\AI\AuditInput;
use App\Data\AI\AuditResult;

interface AuditService
{
    public function audit(AuditInput $input): AuditResult;
}
