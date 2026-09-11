<?php

namespace App\Console\Commands;

use App\Actions\Commerce\CreateSubscription;
use App\Actions\Commerce\OpenSubscriptionPeriod;
use App\Actions\Plans\PublishPlanVersion;
use App\Actions\Validation\EnsurePilotAiPrompts;
use App\Enums\RoleCode;
use App\Enums\SubscriptionStatus;
use App\Models\Plan;
use App\Models\PlanVersion;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class GrantPilotAccessCommand extends Command
{
    protected $signature = 'validation:grant-pilot-access
        {email : Correo del docente participante}
        {--days=45 : Días de vigencia del periodo piloto}';

    protected $description = 'Prepara el acceso piloto curricular: prompts IA manuales y suscripción gratuita temporal, sin desactivar invariantes comerciales.';

    public function handle(
        CreateSubscription $createSubscription,
        OpenSubscriptionPeriod $openPeriod,
        PublishPlanVersion $publishPlanVersion,
        EnsurePilotAiPrompts $ensureAiPrompts,
    ): int {
        $email = mb_strtolower(trim((string) $this->argument('email')));
        $days = (int) $this->option('days');

        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $this->error('PILOT_ACCESS_EMAIL_INVALID');
            return self::FAILURE;
        }
        if ($days < 1 || $days > 180) {
            $this->error('PILOT_ACCESS_DAYS_INVALID');
            return self::FAILURE;
        }

        $customer = User::query()->where('email', $email)->first();
        if (! $customer
            || $customer->status !== 'active'
            || ! $customer->hasVerifiedEmail()
            || ! $customer->hasRole(RoleCode::Customer)) {
            $this->error('PILOT_ACCESS_CUSTOMER_NOT_ELIGIBLE');
            return self::FAILURE;
        }

        $publisher = User::query()
            ->where('status', 'active')
            ->whereHas('roles', fn ($query) => $query->where('code', RoleCode::Administrator->value))
            ->orderBy('id')
            ->first();
        if (! $publisher) {
            $this->error('PILOT_ACCESS_ADMIN_REQUIRED');
            return self::FAILURE;
        }

        try {
            $prompts = $ensureAiPrompts->execute($publisher);
            $this->info(sprintf(
                'Prompts IA listos: generation=v%d audit=v%d correction=v%d',
                $prompts['generation']->number,
                $prompts['audit']->number,
                $prompts['correction']->number,
            ));
        } catch (Throwable $error) {
            report($error);
            $this->error($error->getMessage() !== '' ? $error->getMessage() : 'PILOT_AI_PROMPTS_ENSURE_FAILED');
            return self::FAILURE;
        }

        $operational = Subscription::query()
            ->where('customer_id', $customer->id)
            ->whereIn('status', SubscriptionStatus::operationalValues())
            ->latest('id')
            ->first();

        if ($operational) {
            $current = $operational->currentPeriod();
            if ($current) {
                $this->info(sprintf(
                    'Acceso ya vigente: user=%d subscription=%d period=%d plan_version=%d',
                    $customer->id,
                    $operational->id,
                    $current->id,
                    $operational->plan_version_id,
                ));
                return self::SUCCESS;
            }

            $this->error('PILOT_ACCESS_OPERATIONAL_SUBSCRIPTION_WITHOUT_CURRENT_PERIOD');
            return self::FAILURE;
        }

        try {
            [$subscription, $period, $version] = DB::transaction(function () use (
                $customer,
                $days,
                $createSubscription,
                $openPeriod,
                $publishPlanVersion,
                $publisher,
            ): array {
                $plan = Plan::query()->firstOrCreate(
                    ['code' => 'validation-pilot'],
                    [
                        'name' => 'Piloto de validación curricular',
                        'description' => 'Acceso gratuito y temporal para validar el flujo curricular con docentes piloto.',
                        'active' => true,
                    ],
                );

                $version = PlanVersion::query()
                    ->where('plan_id', $plan->id)
                    ->whereNotNull('published_at')
                    ->orderByDesc('number')
                    ->first();

                if (! $version) {
                    $nextNumber = ((int) PlanVersion::query()->where('plan_id', $plan->id)->max('number')) + 1;
                    $version = PlanVersion::query()->create([
                        'plan_id' => $plan->id,
                        'number' => max(1, $nextNumber),
                        'price_minor' => 0,
                        'currency' => 'MXN',
                        'interval_unit' => 'month',
                        'interval_count' => 1,
                        'max_planning_days' => 31,
                        'planning_limit' => 50,
                        'human_review_limit' => 0,
                        'correction_limit' => 2,
                        'group_limit' => 10,
                        'correction_window_days' => 14,
                        'sla_hours' => 0,
                        'human_review_required' => false,
                        'features' => ['curricular_validation_pilot' => true],
                        'effective_from' => now()->toDateString(),
                        'effective_until' => now()->addDays(180)->toDateString(),
                    ]);
                    $version = $publishPlanVersion($version, $publisher);
                }

                $subscription = $createSubscription($customer, $version, now()->subMinute());
                $period = $openPeriod($subscription, now()->subMinute(), now()->addDays($days));

                return [$subscription, $period, $version];
            }, attempts: 3);

            $this->info(sprintf(
                'Acceso piloto otorgado: user=%d subscription=%d period=%d plan_version=%d vigente_hasta=%s',
                $customer->id,
                $subscription->id,
                $period->id,
                $version->id,
                $period->ends_at->toDateTimeString(),
            ));

            return self::SUCCESS;
        } catch (Throwable $error) {
            report($error);
            $this->error($error->getMessage() !== '' ? $error->getMessage() : 'PILOT_ACCESS_GRANT_FAILED');
            return self::FAILURE;
        }
    }
}
