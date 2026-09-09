<?php

namespace App\Contracts\AI;

use App\Data\AI\GenerationInput;
use App\Data\AI\GenerationResult;

interface GenerationService
{
    public function generate(GenerationInput $input): GenerationResult;
}
