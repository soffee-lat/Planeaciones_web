<?php

namespace App\Data\Planning;

final readonly class AssessmentInstrument
{
    /** @param array<string,mixed> $payload */
    public function __construct(private array $payload) {}

    public function id(): string
    {
        return (string) $this->payload['id'];
    }

    /** @return list<string> */
    public function sessionIds(): array
    {
        return array_values($this->payload['applies_to_sessions'] ?? []);
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return $this->payload;
    }
}
