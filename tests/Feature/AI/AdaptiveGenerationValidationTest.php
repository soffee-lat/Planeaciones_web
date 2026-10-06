<?php

namespace Tests\Feature\AI;

use App\Data\Planning\AdaptiveGeneratedPlan;
use App\Exceptions\AiContractException;
use App\Services\AI\FormatAwareGenerationSchema;
use App\Services\AI\GeneratedPlanDraftValidator;
use Tests\TestCase;

class AdaptiveGenerationValidationTest extends TestCase
{
    public function test_adaptive_generation_accepts_empty_pda_coverage_when_no_real_match_exists(): void
    {
        $payload = [
            'contract_version' => FormatAwareGenerationSchema::ADAPTIVE_CONTRACT_VERSION,
            'core' => [
                'title' => 'Planeación institucional',
                'purpose' => 'Desarrollar el tema sin forzar una referencia curricular irrelevante.',
                'learning_goals' => ['Participar en una actividad pertinente al tema.'],
                'assessment_strategy' => 'Observación formativa durante la actividad.',
                'adaptation_considerations' => [],
                'pda_coverage' => [],
            ],
        ];

        $result = app(GeneratedPlanDraftValidator::class)->validate($payload);

        $this->assertInstanceOf(AdaptiveGeneratedPlan::class, $result);
    }

    public function test_adaptive_generation_still_requires_pda_coverage_to_be_a_list(): void
    {
        $payload = [
            'contract_version' => FormatAwareGenerationSchema::ADAPTIVE_CONTRACT_VERSION,
            'core' => [
                'title' => 'Planeación institucional',
                'purpose' => 'Validar la forma del contrato adaptativo.',
                'learning_goals' => ['Participar en una actividad pertinente al tema.'],
                'assessment_strategy' => 'Observación formativa durante la actividad.',
                'adaptation_considerations' => [],
                'pda_coverage' => ['pda_code' => 'PDA-01'],
            ],
        ];

        $this->expectException(AiContractException::class);
        $this->expectExceptionMessage('ADAPTIVE_GENERATION_CORE_INVALID');

        app(GeneratedPlanDraftValidator::class)->validate($payload);
    }
}
