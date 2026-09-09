<?php

namespace App\Contracts\AI;

use App\Data\AI\AnalysisResult;
use App\Data\AI\DocumentInput;

interface DocumentAnalysisService
{
    public function analyze(DocumentInput $input): AnalysisResult;
}
