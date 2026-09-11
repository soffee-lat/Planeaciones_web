<?php

namespace App\Console\Commands;

use App\Services\Analytics\PilotBehaviorSummary;
use Illuminate\Console\Command;

class PilotBehaviorSummaryCommand extends Command
{
    protected $signature = 'validation:pilot-summary
        {--user= : Limita el resumen a un user_id}
        {--request= : Limita el resumen a un planning_request_id}';

    protected $description = 'Resume las señales mínimas del piloto curricular por docente y planeación';

    public function handle(PilotBehaviorSummary $summary): int
    {
        $userId = $this->nullablePositiveInt($this->option('user'), '--user');
        $requestId = $this->nullablePositiveInt($this->option('request'), '--request');

        $rows = $summary->rows($userId, $requestId);
        if ($rows === []) {
            $this->warn('No hay planeaciones del flujo de validación para los filtros indicados.');
            return self::SUCCESS;
        }

        $this->table([
            'Request', 'User', 'Intento', 'Retorno', 'Aceptadas', 'Rechazadas', 'Agregadas',
            'Mapa (s)', 'Generación (s)', 'DOCX (s)', 'Completada', 'Ahorro', 'Mayor ayuda', 'Siguiente',
        ], array_map(static fn (array $row): array => [
            $row['request_id'],
            $row['user_id'],
            $row['attempt'],
            $row['repeat_planning'] ? 'sí' : 'no',
            $row['accepted'],
            $row['rejected'],
            $row['added'],
            $row['seconds_to_map'] ?? '—',
            $row['seconds_to_generation'] ?? '—',
            $row['seconds_to_docx'] ?? '—',
            $row['completed'] ? 'sí' : 'no',
            $row['saved_time_bucket'] ?? '—',
            $row['most_helpful'] ?? '—',
            $row['next_real_planning'] ?? '—',
        ], $rows));

        $users = collect($rows)->groupBy('user_id');
        $returningUsers = $users->filter(fn ($items) => $items->max('attempt') > 1)->count();
        $completed = collect($rows)->where('completed', true)->count();

        $this->newLine();
        $this->info(sprintf(
            'Docentes observados: %d · con retorno: %d · planeaciones completadas: %d/%d',
            $users->count(),
            $returningUsers,
            $completed,
            count($rows),
        ));

        return self::SUCCESS;
    }

    private function nullablePositiveInt(mixed $value, string $option): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_numeric($value) || (int) $value < 1 || (string) (int) $value !== (string) $value) {
            throw new \InvalidArgumentException("{$option} debe ser un entero positivo.");
        }

        return (int) $value;
    }
}
