<?php

namespace App\Contracts\AI;

use App\Data\AI\CorrectionInput;
use App\Data\AI\CorrectionResult;

interface CorrectionService
{
    public function correct(CorrectionInput $input): CorrectionResult;
}
