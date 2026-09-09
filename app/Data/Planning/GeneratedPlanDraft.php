<?php

namespace App\Data\Planning;

final readonly class GeneratedPlanDraft
{
    /** @param array<string,mixed> $payload */
    public function __construct(private array $payload) {}

    public function contractVersion(): string
    {
        return (string) $this->payload['contract_version'];
    }

    /** @return list<PlanSession> */
    public function sessions(): array
    {
        return array_map(fn (array $session) => new PlanSession($session), $this->payload['sessions']);
    }

    /** @return list<AssessmentInstrument> */
    public function instruments(): array
    {
        return array_map(
            fn (array $instrument) => new AssessmentInstrument($instrument),
            $this->payload['assessment_plan']['instruments'] ?? [],
        );
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return $this->payload;
    }
}
