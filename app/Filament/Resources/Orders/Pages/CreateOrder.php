<?php

namespace App\Filament\Resources\Orders\Pages;

use App\Actions\Commerce\CreateOrder as CreateOrderAction;
use App\Filament\Resources\Orders\OrderResource;
use App\Models\PlanVersion;
use App\Models\User;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateOrder extends CreateRecord
{
    protected static string $resource = OrderResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $customer = User::query()->findOrFail($data['customer_id']);
        $planVersion = PlanVersion::query()->findOrFail($data['plan_version_id']);
        return (new CreateOrderAction())(
            $customer,
            $planVersion,
            (string) $data['idempotency_key'],
            $data['concept'] ?? null,
            auth()->user(),
        );
    }
}
