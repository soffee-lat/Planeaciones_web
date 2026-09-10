<?php

namespace App\Exceptions;

use RuntimeException;

final class DocumentDeliveryException extends RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message = '')
    {
        parent::__construct($message !== '' ? $message : $errorCode);
    }
}
