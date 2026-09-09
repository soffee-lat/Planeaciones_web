<?php

namespace App\Enums;

enum ApprovalKind: string
{
    case Ai = 'ai';
    case Human = 'human';
}
