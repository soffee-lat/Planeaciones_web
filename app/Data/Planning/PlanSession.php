<?php

namespace App\Data\Planning;

final readonly class PlanSession
{
    /** @param array<string,mixed> $payload */
    public function __construct(private array $payload) {}

    public function id(): string
    {
        return (string) $this->payload['id'];
    }

    public function sequence(): int
    {
        return (int) $this->payload['sequence'];
    }

    /** @return list<string> */
    public function contentCodes(): array
    {
        return array_values($this->payload['content_codes'] ?? []);
    }

    /** @return list<string> */
    public function pdaCodes(): array
    {
        return array_values($this->payload['pda_codes'] ?? []);
    }

    /** @return list<PlanMoment> */
    public function moments(): array
    {
        return array_map(fn (array $moment) => new PlanMoment($moment), $this->payload['moments'] ?? []);
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return $this->payload;
    }
}
