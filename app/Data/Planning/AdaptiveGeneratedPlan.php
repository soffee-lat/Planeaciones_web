<?php

namespace App\Data\Planning;

final readonly class AdaptiveGeneratedPlan
{
    /** @param array<string,mixed> $payload */
    public function __construct(private array $payload) {}

    public function contractVersion(): string
    {
        return (string) ($this->payload['contract_version'] ?? '');
    }

    /** @return array<string,mixed> */
    public function core(): array
    {
        return is_array($this->payload['core'] ?? null) ? $this->payload['core'] : [];
    }

    /** @return array<string,mixed> */
    public function fields(): array
    {
        return is_array($this->payload['fields'] ?? null) ? $this->payload['fields'] : [];
    }

    /** @return array<string,mixed> */
    public function custom(): array
    {
        return is_array($this->payload['custom'] ?? null) ? $this->payload['custom'] : [];
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return $this->payload;
    }
}
