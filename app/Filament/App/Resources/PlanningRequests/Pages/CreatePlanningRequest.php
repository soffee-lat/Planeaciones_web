<?php

namespace App\Filament\App\Resources\PlanningRequests\Pages;

use App\Actions\Planning\SyncPlanningRequestSelections;
use App\Enums\PlanningRequestStatus;
use App\Filament\App\Resources\PlanningRequests\PlanningRequestResource;
use App\Models\Group;
use App\Models\PlanningRequest;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

class CreatePlanningRequest extends CreateRecord
{
    protected static string $resource = PlanningRequestResource::class;

    protected function handleRecordCreation(array $data): PlanningRequest
    {
        $group = Group::query()
            ->where('owner_id', auth()->id())
            ->whereNull('archived_at')
            ->findOr($data['group_id'] ?? 0, fn () => null);

        if (! $group) {
            throw ValidationException::withMessages(['group_id' => 'Ese grupo no pertenece a tu cuenta o está archivado.']);
        }

        $contents = Arr::pull($data, 'selected_contents', []);
        $pdas = Arr::pull($data, 'selected_pdas', []);
        $axes = Arr::pull($data, 'selected_axes', []);

        $data['owner_id'] = auth()->id();
        $data['curriculum_version_id'] = $group->curriculum_version_id;
        $data['grade_id'] = $group->grade_id;
        $data['status'] = PlanningRequestStatus::BORRADOR->value;

        /** @var PlanningRequest $request */
        $request = static::getModel()::create($data);

        if ($contents || $pdas || $axes) {
            app(SyncPlanningRequestSelections::class)->execute(auth()->user(), $request, [
                'contents' => $contents,
                'pdas' => $pdas,
                'axes' => $axes,
            ]);
        }

        return $request->refresh();
    }
}
