<?php

namespace App\Actions\Schedules;

use App\Models\Group;
use App\Models\GroupSchedule;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class SaveGroupSchedule
{
    /**
     * @param array<string,mixed> $input
     */
    public function execute(User $actor, Group $group, array $input): GroupSchedule
    {
        Gate::forUser($actor)->authorize('update', $group);

        $data = Validator::make($input, [
            'day_starts_at' => ['required', 'date_format:H:i'],
            'day_ends_at' => ['required', 'date_format:H:i', 'after:day_starts_at'],
            'blocks' => ['array', 'max:100'],
            'blocks.*.day_of_week' => ['required', 'integer', 'between:1,5'],
            'blocks.*.sequence' => ['required', 'integer', 'min:1', 'max:50'],
            'blocks.*.starts_at' => ['required', 'date_format:H:i'],
            'blocks.*.ends_at' => ['required', 'date_format:H:i'],
            'blocks.*.label' => ['required', 'string', 'max:120'],
            'blocks.*.block_type' => ['required', 'in:class,flexible,break,specialist,activity,unavailable'],
            'blocks.*.responsibility' => ['required', 'in:main_teacher,specialist,shared,external,unassigned'],
            'blocks.*.include_in_planning' => ['required', 'boolean'],
            'blocks.*.is_flexible' => ['required', 'boolean'],
            'blocks.*.field_codes' => ['array', 'max:8'],
            'blocks.*.field_codes.*' => ['string', 'max:64'],
            'blocks.*.notes' => ['nullable', 'string', 'max:1000'],
        ])->validate();

        foreach ($data['blocks'] ?? [] as $index => $block) {
            if ($block['ends_at'] <= $block['starts_at']) {
                throw ValidationException::withMessages([
                    "blocks.$index.ends_at" => 'La hora final debe ser posterior a la inicial.',
                ]);
            }
            if ($block['starts_at'] < $data['day_starts_at'] || $block['ends_at'] > $data['day_ends_at']) {
                throw ValidationException::withMessages([
                    "blocks.$index.starts_at" => 'El bloque debe quedar dentro de la jornada.',
                ]);
            }
        }

        $byDay = collect($data['blocks'] ?? [])->groupBy('day_of_week');
        foreach ($byDay as $day => $blocks) {
            $ordered = $blocks->sortBy('starts_at')->values();
            for ($i = 1; $i < $ordered->count(); $i++) {
                if ($ordered[$i]['starts_at'] < $ordered[$i - 1]['ends_at']) {
                    throw ValidationException::withMessages([
                        'blocks' => "Hay bloques traslapados en el día $day.",
                    ]);
                }
            }
        }

        return DB::transaction(function () use ($group, $data): GroupSchedule {
            /** @var GroupSchedule $schedule */
            $schedule = GroupSchedule::query()
                ->where('group_id', $group->id)
                ->where('is_active', true)
                ->lockForUpdate()
                ->latest('id')
                ->first() ?? new GroupSchedule([
                    'group_id' => $group->id,
                    'revision' => 0,
                    'name' => 'Horario habitual',
                    'is_active' => true,
                ]);

            $schedule->fill([
                'day_starts_at' => $data['day_starts_at'],
                'day_ends_at' => $data['day_ends_at'],
                'revision' => (int) $schedule->revision + 1,
                'is_active' => true,
            ])->save();

            $schedule->blocks()->delete();

            foreach (collect($data['blocks'] ?? [])->sortBy([
                ['day_of_week', 'asc'],
                ['starts_at', 'asc'],
            ])->groupBy('day_of_week') as $day => $blocks) {
                foreach ($blocks->values() as $index => $block) {
                    $schedule->blocks()->create([
                        ...$block,
                        'day_of_week' => (int) $day,
                        'sequence' => $index + 1,
                        'field_codes' => array_values($block['field_codes'] ?? []),
                    ]);
                }
            }

            return $schedule->fresh('blocks');
        });
    }
}
