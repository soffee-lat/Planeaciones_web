<?php

namespace App\Enums;

enum CorrectionRequestType: string
{
    case Client = 'client';
    case Internal = 'internal';
}
