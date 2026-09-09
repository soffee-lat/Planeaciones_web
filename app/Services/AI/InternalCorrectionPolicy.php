<?php

namespace App\Services\AI;

use App\Data\AI\AuditResult;
use App\Enums\AiExecutionStage;
use App\Exceptions\AiPipelineException;
use App\Models\AiExecution;
use App\Models\PlanningRequest;

final class InternalCorrectionPolicy
{
    /** @var list<string> */
    private const MUTABLE_ROOTS = [
        'planning',
        'pedagogical_design',
        'sessions',
        'assessment_plan',
        'resources',
        'adaptation_notes',
    ];

    /** @return list<string> */
    public function sectionKeys(AuditResult $result): array
    {
        if ($result->passed) {
            return [];
        }

        $keys = [];
        foreach ($result->findings as $finding) {
            $path = $finding->jsonPath;
            if ($path === '$') {
                $keys = [...$keys, ...self::MUTABLE_ROOTS];
                continue;
            }

            if ($path === '/planning/title' || str_starts_with($path, '/planning/title/')) {
                $keys[] = 'planning';
                continue;
            }
            if ($path === '/planning/project_name' || str_starts_with($path, '/planning/project_name/')) {
                $keys[] = 'planning';
                continue;
            }

            $first = explode('/', ltrim($path, '/'))[0] ?? '';
            if (in_array($first, array_slice(self::MUTABLE_ROOTS, 1), true)) {
                $keys[] = $first;
                continue;
            }

            throw new AiPipelineException('AI_CORRECTION_SCOPE_UNSAFE', $path);
        }

        $keys = array_values(array_unique($keys));
        sort($keys, SORT_STRING);

        return $keys;
    }

    public function nextRound(PlanningRequest $request): int
    {
        return AiExecution::query()
            ->where('request_id', $request->id)
            ->where('input_revision', $request->input_revision)
            ->where('stage', AiExecutionStage::Correction->value)
            ->count() + 1;
    }

    public function assertRoundAvailable(PlanningRequest $request): int
    {
        $maxRounds = (int) config('ai.internal_correction.max_rounds', 2);
        if ($maxRounds < 0 || $maxRounds > 20) {
            throw new AiPipelineException('AI_INTERNAL_CORRECTION_MAX_ROUNDS_INVALID');
        }

        $round = $this->nextRound($request);
        if ($round > $maxRounds) {
            throw new AiPipelineException('AI_INTERNAL_CORRECTION_ROUND_LIMIT_REACHED', sprintf('%d/%d', $round - 1, $maxRounds));
        }

        $this->assertKnownCostBudget($request);

        return $round;
    }

    private function assertKnownCostBudget(PlanningRequest $request): void
    {
        $rawLimit = config('ai.internal_correction.max_known_cost');
        if ($rawLimit === null || $rawLimit === '') {
            return;
        }
        if (! is_numeric($rawLimit) || (float) $rawLimit < 0) {
            throw new AiPipelineException('AI_INTERNAL_CORRECTION_COST_LIMIT_INVALID');
        }

        $currency = strtoupper(trim((string) config('ai.internal_correction.cost_currency', '')));
        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw new AiPipelineException('AI_INTERNAL_CORRECTION_COST_CURRENCY_INVALID');
        }

        $known = AiExecution::query()
            ->where('request_id', $request->id)
            ->where('input_revision', $request->input_revision)
            ->whereNotNull('actual_cost')
            ->get(['actual_cost', 'cost_currency']);

        $sum = 0.0;
        foreach ($known as $execution) {
            if ($execution->cost_currency !== $currency) {
                throw new AiPipelineException('AI_INTERNAL_CORRECTION_COST_CURRENCY_MISMATCH');
            }
            $sum += (float) $execution->actual_cost;
        }

        if ($sum >= (float) $rawLimit) {
            throw new AiPipelineException('AI_INTERNAL_CORRECTION_COST_LIMIT_REACHED');
        }
    }
}
