<?php

namespace App\Data\Planning;

use JsonSerializable;

final readonly class CanonicalPlan implements JsonSerializable
{
    /** @param array<string,mixed> $payload */
    public function __construct(private array $payload) {}

    public function schemaVersion(): string
    {
        return (string) $this->payload['schema_version'];
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return $this->payload;
    }

    /** @return array<string,mixed> */
    public function jsonSerialize(): array
    {
        return $this->payload;
    }

    public function toJson(int $flags = 0): string
    {
        return json_encode($this->payload, $flags | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
