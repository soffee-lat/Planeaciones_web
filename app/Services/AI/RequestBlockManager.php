<?php

namespace App\Services\AI;

use App\Models\PlanningRequest;
use App\Models\RequestBlock;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

final class RequestBlockManager
{
    /** @param array<string,mixed> $details */
    public function open(
        PlanningRequest $request,
        string $code,
        ?string $stage,
        array $details = [],
        ?string $correlationId = null,
    ): RequestBlock {
        $existing = RequestBlock::query()
            ->where('request_id', $request->id)
            ->where('code', $code)
            ->where('stage', $stage)
            ->whereNull('resolved_at')
            ->first();

        if ($existing) {
            return $existing;
        }

        try {
            // El savepoint interno permite recuperar una colisión del índice
            // parcial en PostgreSQL sin dejar abortada la transacción exterior.
            return DB::transaction(fn (): RequestBlock => RequestBlock::query()->create([
                'request_id' => $request->id,
                'code' => $code,
                'stage' => $stage,
                'details' => $details,
                'opened_at' => now(),
                'correlation_id' => $correlationId,
            ]));
        } catch (QueryException $e) {
            $existing = RequestBlock::query()
                ->where('request_id', $request->id)
                ->where('code', $code)
                ->where('stage', $stage)
                ->whereNull('resolved_at')
                ->first();
            if ($existing) {
                return $existing;
            }
            throw $e;
        }
    }

    public function resolve(
        PlanningRequest $request,
        string $code,
        ?string $stage,
        ?User $actor = null,
    ): void {
        $blocks = RequestBlock::query()
            ->where('request_id', $request->id)
            ->where('code', $code)
            ->where('stage', $stage)
            ->whereNull('resolved_at')
            ->lockForUpdate()
            ->get();

        foreach ($blocks as $block) {
            $block->forceFill([
                'resolved_at' => now(),
                'resolved_by' => $actor?->id,
            ])->save();
        }
    }
}
