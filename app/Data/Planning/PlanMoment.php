<?php

namespace App\Data\Planning;

final readonly class PlanMoment
{
    /** @param array<string,mixed> $payload */
    public function __construct(private array $payload) {}

    public function type(): string
    {
        return (string) $this->payload['type'];
    }

    public function minutes(): int
    {
        return (int) $this->payload['minutes'];
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return $this->payload;
    }
}
