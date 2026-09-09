<?php

namespace App\Enums;

enum ReviewChecklistVersionStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
}
