<?php

namespace App\Actions\Plans;

use App\Models\PlanVersion;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Congela una PlanVersion pasando de borrador a publicada. A partir de este
 * momento las condiciones comerciales (límites, precio, revisión humana)
 * quedan inmutables — cualquier cambio requiere una nueva PlanVersion.
 */
class PublishPlanVersion
{
    public function __invoke(PlanVersion $version, User $actor): PlanVersion
    {
        return DB::transaction(function () use ($version, $actor): PlanVersion {
            DB::table('plans')->where('id', $version->plan_id)->lockForUpdate()->first();

            /** @var PlanVersion|null $fresh */
            $fresh = PlanVersion::query()->whereKey($version->id)->lockForUpdate()->first();
            if (! $fresh) {
                throw new RuntimeException('PLAN_VERSION_NOT_FOUND');
            }
            if ($fresh->published_at !== null) {
                throw new RuntimeException('PLAN_VERSION_ALREADY_PUBLISHED');
            }

            $this->validate($fresh);

            $checksum = $this->computeChecksum($fresh);

            // Bypass el hook Eloquent booted() `updating` que protegería una publicada
            // (aún NO lo está — esto sólo evita el falso positivo si otra transacción
            // hubiese publicado en paralelo, lo cual el lockForUpdate ya blindó).
            $fresh->forceFill([
                'published_at' => now(),
                'published_by' => $actor->id,
                'checksum' => $checksum,
            ])->save();

            return $fresh->fresh();
        });
    }

    protected function validate(PlanVersion $v): void
    {
        if ($v->max_planning_days <= 0) {
            throw new RuntimeException('PLAN_VERSION_INVALID_MAX_PLANNING_DAYS');
        }
        if ($v->planning_limit <= 0) {
            throw new RuntimeException('PLAN_VERSION_INVALID_PLANNING_LIMIT');
        }
        if ($v->group_limit <= 0) {
            throw new RuntimeException('PLAN_VERSION_INVALID_GROUP_LIMIT');
        }
        if ($v->human_review_limit < 0) {
            throw new RuntimeException('PLAN_VERSION_INVALID_HUMAN_REVIEW_LIMIT');
        }
        if ($v->correction_limit < 0) {
            throw new RuntimeException('PLAN_VERSION_INVALID_CORRECTION_LIMIT');
        }
        if ($v->price_minor < 0) {
            throw new RuntimeException('PLAN_VERSION_INVALID_PRICE');
        }
        if (! in_array($v->interval_unit, ['month', 'year'], true) || $v->interval_count <= 0) {
            throw new RuntimeException('PLAN_VERSION_INVALID_INTERVAL');
        }
        if ($v->human_review_required && $v->human_review_limit <= 0) {
            throw new RuntimeException('PLAN_VERSION_HUMAN_REVIEW_LIMIT_REQUIRED');
        }
        if ($v->effective_from && $v->effective_until && $v->effective_until->lt($v->effective_from)) {
            throw new RuntimeException('PLAN_VERSION_INVALID_EFFECTIVE_RANGE');
        }
    }

    protected function computeChecksum(PlanVersion $v): string
    {
        $payload = [
            'plan_id' => $v->plan_id,
            'number' => $v->number,
            'price_minor' => (int) $v->price_minor,
            'currency' => $v->currency,
            'interval_unit' => $v->interval_unit,
            'interval_count' => (int) $v->interval_count,
            'max_planning_days' => (int) $v->max_planning_days,
            'planning_limit' => (int) $v->planning_limit,
            'human_review_limit' => (int) $v->human_review_limit,
            'correction_limit' => (int) $v->correction_limit,
            'group_limit' => (int) $v->group_limit,
            'correction_window_days' => (int) $v->correction_window_days,
            'sla_hours' => (int) $v->sla_hours,
            'human_review_required' => (bool) $v->human_review_required,
            'features' => $v->features,
            'effective_from' => $v->effective_from?->toDateString(),
            'effective_until' => $v->effective_until?->toDateString(),
        ];

        return hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
