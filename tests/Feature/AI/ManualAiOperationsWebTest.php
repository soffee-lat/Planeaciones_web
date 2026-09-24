<?php

namespace Tests\Feature\AI;

use App\Enums\AiExecutionStage;
use App\Enums\AiExecutionStatus;
use App\Models\AiExecution;
use Illuminate\Http\UploadedFile;
use Tests\Concerns\BuildsGeneratedPlanDraft;
use Tests\Concerns\CreatesCommercialPlanningScenario;
use Tests\Concerns\CreatesManualAiPipelineScenario;
use Tests\Feature\PedagogyTestCase;

class ManualAiOperationsWebTest extends PedagogyTestCase
{
    use BuildsGeneratedPlanDraft;
    use CreatesCommercialPlanningScenario;
    use CreatesManualAiPipelineScenario;

    public function test_admin_summary_reports_waiting_manual_work_and_customer_is_forbidden(): void
    {
        $scene = $this->waitingManualAuditScenario();

        $customer = $this->customer();
        $this->actingAs($customer)
            ->getJson(route('admin.ai-operations.summary'))
            ->assertForbidden();

        $admin = $this->admin();
        $this->actingAs($admin)
            ->getJson(route('admin.ai-operations.summary'))
            ->assertOk()
            ->assertJson([
                'count' => 1,
                'by_stage' => [
                    'generation' => 0,
                    'audit' => 1,
                    'correction' => 0,
                ],
            ]);

        $this->assertSame(AiExecutionStatus::WaitingManual, $scene['audit']->fresh()->status);
    }

    public function test_admin_can_download_exact_manual_package(): void
    {
        $scene = $this->waitingManualAuditScenario();
        $audit = $scene['audit']->fresh(['manualPackage']);
        $expected = \Illuminate\Support\Facades\Storage::disk($audit->manualPackage->disk)
            ->get($audit->manualPackage->path);

        $response = $this->actingAs($this->admin())
            ->get(route('admin.ai-operations.download', $audit));

        $response->assertOk();
        $this->assertSame($expected, $response->streamedContent());
        $this->assertStringContainsString(
            'soffee-audit-execution-' . $audit->id . '.json',
            (string) $response->headers->get('content-disposition'),
        );
    }

    public function test_importing_failed_audit_routes_and_prepares_correction_without_terminal(): void
    {
        $scene = $this->waitingManualAuditScenario();
        $audit = $scene['audit'];

        $payload = [
            'schema_version' => 'audit_result_v1',
            'passed' => false,
            'findings' => [[
                'code' => 'LANGUAGE_QUALITY',
                'severity' => 'medium',
                'json_path' => '/sessions/0',
                'explanation' => 'Hallazgo ficticio para probar la consola web.',
                'expected_correction' => 'Corregir la sesión indicada sin alterar el currículo.',
            ]],
        ];

        $file = UploadedFile::fake()->createWithContent(
            'audit-result.json',
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        );

        $response = $this->actingAs($this->admin())
            ->post(route('admin.ai-operations.result', $audit), [
                'result_file' => $file,
            ]);

        $response->assertRedirect('/admin/ai-operations');
        $this->assertSame(AiExecutionStatus::Succeeded, $audit->fresh()->status);

        $correction = AiExecution::query()
            ->where('request_id', $scene['request']->id)
            ->where('stage', AiExecutionStage::Correction->value)
            ->sole();

        $this->assertSame(AiExecutionStatus::WaitingManual, $correction->status);
        $this->assertNotNull($correction->manualPackage);
    }
}
