<?php

namespace App\Exceptions;

use RuntimeException;

class DocumentRenderException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        public readonly ?string $detail = null,
    ) {
        parent::__construct($detail === null ? $errorCode : $errorCode . ':' . $detail);
    }
}
