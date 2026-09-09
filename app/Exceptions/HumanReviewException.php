<?php

namespace App\Exceptions;

use RuntimeException;

final class HumanReviewException extends RuntimeException
{
    public function __construct(public readonly string $errorCode)
    {
        parent::__construct($errorCode);
    }
}
