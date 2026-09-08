<?php

namespace App\Actions\Commerce;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\PlanVersion;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Crea una Order en estado `pending` congelando precio/moneda y plan_version.
 *
 * Reglas:
 *   - PlanVersion debe estar publicada (validado por trigger BD).
 *   - Precio y moneda se copian de la PlanVersion en el momento de crear la
 *     Order; publicar una nueva versión no muta la Order histórica (trigger
 *     order_paid_immutability_guard bloquea cambios en paid/refunded).
 *   - Idempotencia por `idempotency_key`: mismo key + mismo (customer, plan,
 *     plan_version) devuelve la Order existente; payload distinto lanza
 *     `ORDER_IDEMPOTENCY_CONFLICT`.
 */
class CreateOrder
{
    public function __invoke(
        User $customer,
        PlanVersion $planVersion,
        string $idempotencyKey,
        ?string $concept = null,
        ?User $createdBy = null,
    ): Order {
        return DB::transaction(function () use ($customer, $planVersion, $idempotencyKey, $concept, $createdBy): Order {
            /** @var Order|null $existing */
            $existing = Order::query()->where('idempotency_key', $idempotencyKey)->lockForUpdate()->first();
            if ($existing) {
                if (
                    $existing->customer_id !== $customer->id
                    || $existing->plan_id !== $planVersion->plan_id
                    || $existing->plan_version_id !== $planVersion->id
                ) {
                    throw new RuntimeException('ORDER_IDEMPOTENCY_CONFLICT');
                }
                return $existing;
            }

            /** @var PlanVersion|null $fresh */
            $fresh = PlanVersion::query()->whereKey($planVersion->id)->lockForUpdate()->first();
            if (! $fresh) {
                throw new RuntimeException('PLAN_VERSION_NOT_FOUND');
            }
            if ($fresh->published_at === null) {
                throw new RuntimeException('ORDER_PLAN_VERSION_NOT_PUBLISHED');
            }

            return Order::query()->create([
                'customer_id' => $customer->id,
                'plan_id' => $fresh->plan_id,
                'plan_version_id' => $fresh->id,
                'created_by' => $createdBy?->id,
                'concept' => $concept ?? sprintf('Compra %s v%d', $fresh->plan->name ?? 'plan', $fresh->number),
                'total_minor' => (int) $fresh->price_minor,
                'currency' => strtoupper((string) $fresh->currency),
                'status' => OrderStatus::Pending->value,
                'idempotency_key' => $idempotencyKey,
            ]);
        });
    }
}
