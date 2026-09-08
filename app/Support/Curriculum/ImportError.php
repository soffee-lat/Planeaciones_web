<?php

namespace App\Support\Curriculum;

class ImportError
{
    public function __construct(
        public readonly string $code,
        public readonly string $message,
        public readonly string $location = '',
    ) {}

    public function toArray(): array
    {
        return ['code' => $this->code, 'message' => $this->message, 'location' => $this->location];
    }
}
