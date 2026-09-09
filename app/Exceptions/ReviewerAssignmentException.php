<?php

namespace App\Exceptions;

use RuntimeException;

final class ReviewerAssignmentException extends RuntimeException
{
    public function __construct(public readonly string $errorCode)
    {
        parent::__construct($errorCode);
    }
}
