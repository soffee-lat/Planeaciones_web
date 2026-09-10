<?php

namespace App\Enums;

enum FileCategory: string
{
    case InstitutionalFormat = 'institutional_format';
    case FormatSample = 'format_sample';
    case Book = 'book';
    case Material = 'material';
    case PreviousPlan = 'previous_plan';
    case Evidence = 'evidence';
    case Result = 'result';
    case Other = 'other';
}
