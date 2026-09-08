<?php

namespace App\Filament\Resources\Subscriptions\Pages;

use App\Actions\Commerce\CreateSubscription as CreateSubscriptionAction;
use App\Filament\Resources\Subscriptions\SubscriptionResource;
use App\Models\PlanVersion;
use App\Models\User;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class CreateSubscription extends CreateRecord
{
    protected static string $resource = SubscriptionResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $customer = User::query()->findOrFail($data['customer_id']);
        $planVersion = PlanVersion::query()->findOrFail($data['plan_version_id']);
        $startsAt = isset($data['starts_at']) ? Carbon::parse($data['starts_at']) : now();
        return (new CreateSubscriptionAction())($customer, $planVersion, $startsAt);
    }
}
