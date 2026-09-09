<?php

namespace App\Enums;

enum PromptCategory: string
{
    case Generation = 'generation';
    case Audit = 'audit';
    case Correction = 'correction';
    case DocumentAnalysis = 'document_analysis';
    case FormatAdaptation = 'format_adaptation';
}
