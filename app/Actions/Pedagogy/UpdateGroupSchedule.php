<?php

namespace App\Actions\Pedagogy;

use App\Models\Group;
use App\Models\GroupSchedule;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class UpdateGroupSchedule
{
    /**
     * @param array{
     *   name?:string,
     *   active_days?:list<int>,
     *   exceptions?:list<array{date:string,label?:string}>,
     *   day_starts_at:string,
     *   day_ends_at:string,
     *   blocks:list<array<string,mixed>>
     * } $payload
     */
    public function execute(User $actor, Group $group, array $payload): GroupSchedule
    {
        Gate::forUser($actor)->authorize('update', $group);

        $normalized = $this->normalize($payload);

        return DB::transaction(function () use ($group, $normalized): GroupSchedule {
            /** @var Group $lockedGroup */
            $lockedGroup = Group::query()->whereKey($group->id)->lockForUpdate()->firstOrFail();

            /** @var GroupSchedule $schedule */
            $schedule = GroupSchedule::query()
                ->where('group_id', $lockedGroup->id)
                ->lockForUpdate()
                ->first();

            if (! $schedule) {
                $schedule = GroupSchedule::query()->create([
                    'group_id' => $lockedGroup->id,
                    'revision' => 0,
                    'name' => $normalized['name'],
                    'active_days' => $normalized['active_days'],
                    'exceptions' => $normalized['exceptions'],
                    'day_starts_at' => $normalized['day_starts_at'],
                    'day_ends_at' => $normalized['day_ends_at'],
                ]);
                $schedule = GroupSchedule::query()->whereKey($schedule->id)->lockForUpdate()->firstOrFail();
            }

            $schedule->forceFill([
                'name' => $normalized['name'],
                'active_days' => $normalized['active_days'],
                'exceptions' => $normalized['exceptions'],
                'day_starts_at' => $normalized['day_starts_at'],
                'day_ends_at' => $normalized['day_ends_at'],
                'revision' => (int) $schedule->revision + 1,
            ])->save();

            $schedule->blocks()->delete();

            foreach ($normalized['blocks'] as $block) {
                $schedule->blocks()->create($block);
            }

            return $schedule->fresh('blocks');
        }, attempts: 3);
    }

    /**
     * @param array<string,mixed> $payload
     * @return array{name:string,active_days:list<int>,exceptions:list<array{date:string,label:string}>,day_starts_at:string,day_ends_at:string,blocks:list<array<string,mixed>>}
     */
    private function normalize(array $payload): array
    {
        $name = trim((string) ($payload['name'] ?? 'Horario habitual'));
        if ($name === '') {
            $name = 'Horario habitual';
        }
        if (mb_strlen($name) > 120) {
            throw ValidationException::withMessages(['schedule' => 'El nombre del horario es demasiado largo.']);
        }

        $activeDays = $payload['active_days'] ?? [1, 2, 3, 4, 5];
        if (! is_array($activeDays) || ! array_is_list($activeDays)) {
            throw ValidationException::withMessages(['schedule' => 'Los días activos del horario son inválidos.']);
        }
        $activeDays = array_values(array_unique(array_map('intval', $activeDays)));
        sort($activeDays);
        if ($activeDays === [] || count($activeDays) > 7 || collect($activeDays)->contains(fn (int $day) => $day < 1 || $day > 7)) {
            throw ValidationException::withMessages(['schedule' => 'Selecciona al menos un día válido de clase.']);
        }

        $exceptions = $payload['exceptions'] ?? [];
        if (! is_array($exceptions) || ! array_is_list($exceptions) || count($exceptions) > 100) {
            throw ValidationException::withMessages(['schedule' => 'Las excepciones del horario son inválidas.']);
        }
        $normalizedExceptions = [];
        foreach ($exceptions as $exception) {
            if (! is_array($exception)) {
                throw ValidationException::withMessages(['schedule' => 'Una excepción del horario es inválida.']);
            }
            try {
                $date = CarbonImmutable::parse((string) ($exception['date'] ?? ''))->format('Y-m-d');
            } catch (\Throwable) {
                throw ValidationException::withMessages(['schedule' => 'Una fecha sin clase es inválida.']);
            }
            $label = trim((string) ($exception['label'] ?? 'Sin clases'));
            if ($label === '') {
                $label = 'Sin clases';
            }
            $normalizedExceptions[$date] = [
                'date' => $date,
                'label' => mb_substr($label, 0, 160),
            ];
        }
        ksort($normalizedExceptions);
        $normalizedExceptions = array_values($normalizedExceptions);

        $dayStartsAt = $this->normalizeTime($payload['day_starts_at'] ?? null, 'Hora de entrada');
        $dayEndsAt = $this->normalizeTime($payload['day_ends_at'] ?? null, 'Hora de salida');

        if ($this->minutes($dayStartsAt) >= $this->minutes($dayEndsAt)) {
            throw ValidationException::withMessages(['schedule' => 'La hora de salida debe ser posterior a la hora de entrada.']);
        }

        $blocks = $payload['blocks'] ?? [];
        if (! is_array($blocks) || ! array_is_list($blocks)) {
            throw ValidationException::withMessages(['schedule' => 'Los bloques del horario son inválidos.']);
        }
        if (count($blocks) > 100) {
            throw ValidationException::withMessages(['schedule' => 'El horario supera el máximo de 100 bloques.']);
        }

        $allowedTypes = ['instructional', 'flexible', 'break', 'external'];
        $allowedResponsibilities = ['main_teacher', 'specialist', 'shared', 'external', 'unassigned'];
        $normalizedBlocks = [];

        foreach ($blocks as $index => $block) {
            if (! is_array($block)) {
                throw ValidationException::withMessages(['schedule' => 'Uno de los bloques es inválido.']);
            }

            $day = (int) ($block['day_of_week'] ?? 0);
            if ($day < 1 || $day > 7) {
                throw ValidationException::withMessages(['schedule' => 'Cada bloque debe pertenecer a un día válido.']);
            }

            $startsAt = $this->normalizeTime($block['starts_at'] ?? null, 'Inicio de bloque');
            $endsAt = $this->normalizeTime($block['ends_at'] ?? null, 'Fin de bloque');

            if ($this->minutes($startsAt) >= $this->minutes($endsAt)) {
                throw ValidationException::withMessages(['schedule' => 'Cada bloque debe terminar después de comenzar.']);
            }
            if ($this->minutes($startsAt) < $this->minutes($dayStartsAt)
                || $this->minutes($endsAt) > $this->minutes($dayEndsAt)) {
                throw ValidationException::withMessages(['schedule' => 'Todos los bloques deben quedar dentro de la jornada escolar.']);
            }

            $type = (string) ($block['block_type'] ?? 'flexible');
            if (! in_array($type, $allowedTypes, true)) {
                throw ValidationException::withMessages(['schedule' => 'Tipo de bloque no permitido.']);
            }

            $responsibility = (string) ($block['responsibility'] ?? 'main_teacher');
            if (! in_array($responsibility, $allowedResponsibilities, true)) {
                throw ValidationException::withMessages(['schedule' => 'Responsable del bloque no permitido.']);
            }

            $label = trim((string) ($block['label'] ?? ''));
            if ($label === '') {
                $label = $type === 'break' ? 'Recreo' : 'Tiempo disponible';
            }
            if (mb_strlen($label) > 160) {
                throw ValidationException::withMessages(['schedule' => 'Una etiqueta del horario es demasiado larga.']);
            }

            $fieldCodes = $block['field_codes'] ?? [];
            if (! is_array($fieldCodes) || ! array_is_list($fieldCodes)) {
                throw ValidationException::withMessages(['schedule' => 'La relación con campos formativos es inválida.']);
            }
            $fieldCodes = array_values(array_unique(array_filter(array_map(
                fn ($value) => strtoupper(trim((string) $value)),
                $fieldCodes,
            ), fn ($value) => $value !== '' && preg_match('/^[A-Z0-9_-]{1,32}$/', $value) === 1)));

            $include = (bool) ($block['include_in_planning'] ?? true);
            if ($type === 'break' || $type === 'external' || $responsibility === 'external') {
                $include = false;
            }

            $normalizedBlocks[] = [
                'day_of_week' => $day,
                'sequence' => (int) ($block['sequence'] ?? ($index + 1)),
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'block_type' => $type,
                'label' => $label,
                'responsibility' => $responsibility,
                'include_in_planning' => $include,
                'field_codes' => $fieldCodes,
                'notes' => ($notes = trim((string) ($block['notes'] ?? ''))) === '' ? null : mb_substr($notes, 0, 2000),
            ];
        }

        usort($normalizedBlocks, fn (array $a, array $b) => [$a['day_of_week'], $a['starts_at'], $a['ends_at']] <=> [$b['day_of_week'], $b['starts_at'], $b['ends_at']]);

        $byDay = [];
        foreach ($normalizedBlocks as $block) {
            $byDay[$block['day_of_week']][] = $block;
        }

        $resequenced = [];
        foreach ($byDay as $dayBlocks) {
            $previousEnd = null;
            foreach ($dayBlocks as $position => $block) {
                if ($previousEnd !== null && $this->minutes($block['starts_at']) < $this->minutes($previousEnd)) {
                    throw ValidationException::withMessages(['schedule' => 'Hay bloques que se traslapan. Ajusta sus horarios antes de guardar.']);
                }
                $block['sequence'] = $position + 1;
                $resequenced[] = $block;
                $previousEnd = $block['ends_at'];
            }
        }

        return [
            'name' => $name,
            'active_days' => $activeDays,
            'exceptions' => $normalizedExceptions,
            'day_starts_at' => $dayStartsAt,
            'day_ends_at' => $dayEndsAt,
            'blocks' => $resequenced,
        ];
    }

    private function normalizeTime(mixed $value, string $label): string
    {
        $value = trim((string) $value);
        foreach (['H:i', 'H:i:s'] as $format) {
            try {
                $parsed = CarbonImmutable::createFromFormat($format, $value);
                if ($parsed !== false) {
                    return $parsed->format('H:i:s');
                }
            } catch (\Throwable) {
                // Intentar siguiente formato.
            }
        }

        throw ValidationException::withMessages(['schedule' => $label . ' inválida.']);
    }

    private function minutes(string $time): int
    {
        [$hour, $minute] = array_map('intval', explode(':', $time));

        return ($hour * 60) + $minute;
    }
}
