<?php
use App\Models\User;
use App\Models\Plan;
use App\Actions\Commerce\CreateSubscription;
use App\Actions\Commerce\OpenSubscriptionPeriod;
use App\Actions\Commerce\ReservePlanningUnits;
use App\Enums\UsageResource;
use App\Enums\RoleCode;

$customer = User::factory()->withRole(RoleCode::Customer)->create([
    'name' => 'Docente Demo 3B',
    'email' => 'docente-demo-3b@example.test',
]);
$plan = Plan::where('code', 'BASIC-DEMO')->firstOrFail();
$pv = $plan->versions()->whereNotNull('published_at')->orderByDesc('number')->first();
$sub = (new CreateSubscription())($customer, $pv, now());
$period = (new OpenSubscriptionPeriod())($sub, now()->subDay(), now()->addDays(30));
(new ReservePlanningUnits())($period, UsageResource::Planning, 2, 'demo-op-1');
echo "seed_ok subscription={$sub->id} period={$period->id}\n";
