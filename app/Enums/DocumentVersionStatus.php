<?php

namespace App\Enums;

enum DocumentVersionStatus: string
{
    case Draft = 'draft';
    case Validated = 'validated';
    case Approved = 'approved';
}
