<?php

namespace App\Exceptions;

use RuntimeException;

class AiContractException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        public readonly string $jsonPath = '$',
        ?string $detail = null,
    ) {
        parent::__construct($errorCode . ($jsonPath !== '$' ? ":{$jsonPath}" : '') . ($detail ? ":{$detail}" : ''));
    }
}
