<?php

namespace App\Filament\Admin\Pages;

use App\Enums\AiExecutionMode;
use App\Enums\AiExecutionStage;
use App\Enums\AiExecutionStatus;
use App\Enums\RoleCode;
use App\Models\AiExecution;
use App\Models\OutboxEvent;
use Illuminate\Support\Collection;

class AiOperations extends \Filament\Pages\Page
{
    protected static ?string $title = 'Operación IA manual';

    protected static ?string $navigationLabel = 'Operación IA';

    protected static ?string $slug = 'ai-operations';

    protected string $view = 'filament.admin.pages.ai-operations';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user
            && $user->status === 'active'
            && $user->hasRole(RoleCode::Administrator);
    }

    public static function getNavigationBadge(): ?string
    {
        if (! static::canAccess()) {
            return null;
        }

        $count = static::waitingManualQuery()->count();

        return $count > 0 ? (string) $count : null;
    }

    public function pendingExecutions(): Collection
    {
        return static::waitingManualQuery()
            ->with([
                'manualPackage',
                'request.owner',
                'request.group',
            ])
            ->orderBy('created_at')
            ->get();
    }

    public function recentExecutions(): Collection
    {
        return AiExecution::query()
            ->where('mode', AiExecutionMode::Manual->value)
            ->where('status', AiExecutionStatus::Succeeded->value)
            ->whereIn('stage', [
                AiExecutionStage::Generation->value,
                AiExecutionStage::Audit->value,
                AiExecutionStage::Correction->value,
            ])
            ->with(['request.owner'])
            ->latest('finished_at')
            ->limit(16)
            ->get();
    }

    public function historyForRequest(int $requestId): Collection
    {
        return AiExecution::query()
            ->where('request_id', $requestId)
            ->where('mode', AiExecutionMode::Manual->value)
            ->whereIn('stage', [
                AiExecutionStage::Generation->value,
                AiExecutionStage::Audit->value,
                AiExecutionStage::Correction->value,
            ])
            ->orderBy('id')
            ->get();
    }

    public function pendingOutboxCount(): int
    {
        return OutboxEvent::query()
            ->whereNull('published_at')
            ->where('available_at', '<=', now())
            ->where(function ($query): void {
                $query->whereNull('lease_expires_at')
                    ->orWhere('lease_expires_at', '<=', now());
            })
            ->count();
    }

    public function executionLabel(AiExecution $execution): string
    {
        if ($execution->stage === AiExecutionStage::Audit && $this->hasPriorCorrection($execution)) {
            return 'Reauditoría';
        }

        return $this->stageLabel($execution->stage);
    }

    /** @return array{headline:string,description:string,download:string,process:string,upload:string,submit:string,next:string} */
    public function actionGuide(AiExecution $execution): array
    {
        if ($execution->stage === AiExecutionStage::Generation) {
            return [
                'headline' => 'Generar la primera versión',
                'description' => 'Este paquete contiene la solicitud, el currículo y el horario congelados. La IA debe devolver el JSON de generación.',
                'download' => '1. Descargar paquete de generación',
                'process' => '2. Procesa el paquete con la IA generadora. No subas aquí el paquete descargado: espera el JSON de resultado que devuelve la IA.',
                'upload' => '3. Subir resultado de generación',
                'submit' => 'Importar generación y preparar auditoría',
                'next' => 'Después de importarlo, Soffee preparará automáticamente la Auditoría.',
            ];
        }

        if ($execution->stage === AiExecutionStage::Correction) {
            return [
                'headline' => 'Aplicar las correcciones autorizadas',
                'description' => 'El paquete contiene los hallazgos y limita qué secciones puede modificar la IA.',
                'download' => '1. Descargar paquete de corrección',
                'process' => '2. Procesa el paquete con la IA correctora. Debe modificar únicamente el alcance autorizado y devolver el JSON de corrección.',
                'upload' => '3. Subir resultado de corrección',
                'submit' => 'Importar corrección y preparar reauditoría',
                'next' => 'Después de importarlo, Soffee creará una nueva versión y abrirá una Reauditoría.',
            ];
        }

        if ($this->hasPriorCorrection($execution)) {
            return [
                'headline' => 'Comprobar la versión corregida',
                'description' => 'Esta es una Reauditoría: valida que los hallazgos anteriores quedaron resueltos sin romper currículo, horario ni estructura.',
                'download' => '1. Descargar paquete de reauditoría',
                'process' => '2. Procesa el paquete con la IA auditora. Debe devolver sólo el JSON de auditoría, indicando si aprueba o qué hallazgos permanecen.',
                'upload' => '3. Subir resultado de reauditoría',
                'submit' => 'Importar reauditoría y continuar',
                'next' => 'Si aprueba, la planeación queda lista para la siguiente etapa. Si aún hay hallazgos permitidos, se abrirá otra corrección.',
            ];
        }

        return [
            'headline' => 'Auditar la versión generada',
            'description' => 'La IA auditora revisará la planeación contra currículo, PDA, horario, contexto del grupo y reglas pedagógicas.',
            'download' => '1. Descargar paquete de auditoría',
            'process' => '2. Procesa el paquete con la IA auditora. No debe regenerar la planeación: debe devolver el JSON de auditoría.',
            'upload' => '3. Subir resultado de auditoría',
            'submit' => 'Importar auditoría y continuar',
            'next' => 'Si aprueba, termina el ciclo de IA. Si encuentra hallazgos corregibles, Soffee abrirá automáticamente Corrección.',
        ];
    }

    /** @return list<array{label:string,status:string,detail:?string}> */
    public function workflowSteps(AiExecution $execution): array
    {
        $current = match ($execution->stage) {
            AiExecutionStage::Generation => 1,
            AiExecutionStage::Correction => 3,
            AiExecutionStage::Audit => $this->hasPriorCorrection($execution) ? 4 : 2,
            default => 1,
        };

        $labels = [
            1 => ['Generación', null],
            2 => ['Auditoría inicial', null],
            3 => ['Corrección', 'Sólo si hay hallazgos'],
            4 => ['Reauditoría', 'Después de una corrección'],
            5 => ['Aprobación', null],
        ];

        $steps = [];
        foreach ($labels as $position => [$label, $detail]) {
            $status = $position < $current ? 'done' : ($position === $current ? 'current' : 'pending');
            $steps[] = ['label' => $label, 'status' => $status, 'detail' => $detail];
        }

        return $steps;
    }

    public function resultLabel(AiExecution $execution): string
    {
        if ($execution->stage === AiExecutionStage::Audit) {
            $passed = data_get($execution->audit_report, 'passed');

            return $passed === true
                ? 'Aprobada'
                : ($passed === false ? 'Con hallazgos' : 'Procesada');
        }

        if ($execution->stage === AiExecutionStage::Generation) {
            return $execution->resulting_version_id
                ? 'Versión #' . $execution->resulting_version_id . ' creada'
                : 'Procesada';
        }

        if ($execution->stage === AiExecutionStage::Correction) {
            return $execution->resulting_version_id
                ? 'Versión #' . $execution->resulting_version_id . ' corregida'
                : 'Procesada';
        }

        return 'Procesada';
    }

    public function stageLabel(AiExecutionStage $stage): string
    {
        return match ($stage) {
            AiExecutionStage::Generation => 'Generación',
            AiExecutionStage::Audit => 'Auditoría',
            AiExecutionStage::Correction => 'Corrección',
            default => ucfirst(str_replace('_', ' ', $stage->value)),
        };
    }

    private function hasPriorCorrection(AiExecution $execution): bool
    {
        return AiExecution::query()
            ->where('request_id', $execution->request_id)
            ->where('mode', AiExecutionMode::Manual->value)
            ->where('stage', AiExecutionStage::Correction->value)
            ->where('id', '<', $execution->id)
            ->exists();
    }

    private static function waitingManualQuery()
    {
        return AiExecution::query()
            ->where('mode', AiExecutionMode::Manual->value)
            ->where('status', AiExecutionStatus::WaitingManual->value)
            ->whereIn('stage', [
                AiExecutionStage::Generation->value,
                AiExecutionStage::Audit->value,
                AiExecutionStage::Correction->value,
            ])
            ->whereHas('manualPackage');
    }
}
