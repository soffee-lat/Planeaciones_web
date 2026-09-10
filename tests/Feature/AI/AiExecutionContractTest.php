<?php

namespace Tests\Feature\AI;

use App\Actions\AI\PublishPromptVersion;
use App\Contracts\AI\AuditService;
use App\Contracts\AI\CorrectionService;
use App\Contracts\AI\DocumentAnalysisService;
use App\Contracts\AI\GenerationService;
use App\Enums\AiExecutionMode;
use App\Enums\AiExecutionStage;
use App\Enums\AiExecutionStatus;
use App\Models\AiExecution;
use App\Models\FormatVersion;
use App\Models\PromptVersion;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Feature\PedagogyTestCase;

class AiExecutionContractTest extends PedagogyTestCase
{
    private function publishedPrompt(): PromptVersion
    {
        $version = PromptVersion::factory()->create();
        return app(PublishPromptVersion::class)->execute($this->admin(), $version);
    }

    public function test_registra_ejecucion_pendiente_sin_llamar_proveedor(): void
    {
        $prompt = $this->publishedPrompt();
        $formatVersion = FormatVersion::factory()->create();
        $execution = AiExecution::factory()->create([
            'prompt_version_id' => $prompt->id,
            'request_id' => null,
            'format_version_id' => $formatVersion->id,
            'stage' => AiExecutionStage::DocumentAnalysis->value,
            'mode' => AiExecutionMode::Manual->value,
            'status' => AiExecutionStatus::Pending->value,
            'provider' => null,
            'model' => null,
            'estimated_cost' => null,
            'actual_cost' => null,
        ]);

        $this->assertSame(AiExecutionStatus::Pending, $execution->status);
        $this->assertNull($execution->provider);
        $this->assertNull($execution->model);
        $this->assertNull($execution->actual_cost);
    }

    public function test_bd_rechaza_ejecucion_con_prompt_borrador(): void
    {
        $draft = PromptVersion::factory()->create();
        $formatVersion = FormatVersion::factory()->create();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('AI_EXECUTION_PROMPT_VERSION_NOT_PUBLISHED');
        AiExecution::factory()->create([
            'prompt_version_id' => $draft->id,
            'format_version_id' => $formatVersion->id,
        ]);
    }

    public function test_bd_requiere_sujeto_request_o_format(): void
    {
        $prompt = $this->publishedPrompt();

        $this->expectException(QueryException::class);
        DB::table('ai_executions')->insert([
            'request_id' => null,
            'format_version_id' => null,
            'stage' => 'generation',
            'mode' => 'manual',
            'prompt_version_id' => $prompt->id,
            'input_revision' => null,
            'input_manifest' => '{}',
            'operation_key' => 'invalid:no-subject',
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_request_execution_requiere_input_revision(): void
    {
        $prompt = $this->publishedPrompt();
        $scene = $this->seedFullTeacher();
        $request = \App\Models\PlanningRequest::factory()->create([
            'owner_id' => $scene['user']->id,
            'group_id' => $scene['group']->id,
            'curriculum_version_id' => $scene['version']->id,
            'grade_id' => $scene['grade']->id,
        ]);

        $this->expectException(QueryException::class);
        AiExecution::factory()->create([
            'prompt_version_id' => $prompt->id,
            'request_id' => $request->id,
            'format_version_id' => null,
            'input_revision' => null,
        ]);
    }

    public function test_operation_key_es_unica(): void
    {
        $prompt = $this->publishedPrompt();
        AiExecution::factory()->create([
            'prompt_version_id' => $prompt->id,
            'operation_key' => 'ai:unique:test',
        ]);

        $this->expectException(QueryException::class);
        AiExecution::factory()->create([
            'prompt_version_id' => $prompt->id,
            'operation_key' => 'ai:unique:test',
        ]);
    }

    public function test_identidad_de_ejecucion_es_inmutable_pero_status_puede_avanzar(): void
    {
        $execution = AiExecution::factory()->create();
        $execution->status = AiExecutionStatus::WaitingManual;
        $execution->save();
        $this->assertSame(AiExecutionStatus::WaitingManual, $execution->fresh()->status);

        $execution = $execution->fresh();
        $execution->operation_key = 'changed:key';
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('AI_EXECUTION_IDENTITY_IMMUTABLE');
        $execution->save();
    }

    public function test_bd_impide_borrar_historial_de_ejecucion(): void
    {
        $execution = AiExecution::factory()->create();
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('AI_EXECUTION_HISTORY_IMMUTABLE');
        DB::table('ai_executions')->where('id', $execution->id)->delete();
    }

    public function test_4a_define_interfaces_pero_no_registra_adapters_reales(): void
    {
        $this->assertFalse(app()->bound(GenerationService::class));
        $this->assertFalse(app()->bound(AuditService::class));
        $this->assertFalse(app()->bound(CorrectionService::class));
        $this->assertFalse(app()->bound(DocumentAnalysisService::class));
    }

    public function test_cliente_y_revisor_no_pueden_ver_ejecuciones_internas(): void
    {
        $execution = AiExecution::factory()->create();
        $this->assertFalse($this->customer()->can('view', $execution));
        $this->assertFalse($this->reviewer()->can('view', $execution));
        $this->assertTrue($this->admin()->can('view', $execution));
    }
}
