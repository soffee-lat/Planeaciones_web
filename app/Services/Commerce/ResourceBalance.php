<?php

namespace App\Services\Commerce;

use App\Enums\UsageResource;

/**
 * Foto inmutable del balance comercial de un SubscriptionPeriod para un
 * recurso concreto.
 *
 * Fórmula oficial (verbatim DATABASE.md):
 *   reservado + consumido ≤ límite aplicable al recurso
 * → available = limit - reserved - consumed
 *
 * `consumed` sigue restando de `available`: una vez que la reserva se
 * transformó en consumo, esas unidades quedan gastadas de forma permanente.
 */
final readonly class ResourceBalance
{
    public function __construct(
        public UsageResource $resource,
        public int $limit,
        public int $reserved,
        public int $consumed,
    ) {
    }

    public function available(): int
    {
        return $this->limit - $this->reserved - $this->consumed;
    }

    /** @return array{resource:string,limit:int,reserved:int,consumed:int,available:int} */
    public function toArray(): array
    {
        return [
            'resource' => $this->resource->value,
            'limit' => $this->limit,
            'reserved' => $this->reserved,
            'consumed' => $this->consumed,
            'available' => $this->available(),
        ];
    }
}
