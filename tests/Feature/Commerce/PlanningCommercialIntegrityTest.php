<?php

namespace Tests\Feature\Commerce;

use App\Actions\Commerce\OpenSubscriptionPeriod;
use App\Actions\Commerce\ReservePlanningUnits;
use App\Enums\PlanningRequestStatus;
use App\Enums\UsageResource;
use App\Models\PlanningRequest;
use App\Models\RequestBlock;
use App\Models\SubscriptionPeriod;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesCommercialPlanningScenario;
use Tests\Feature\PedagogyTestCase;

class PlanningCommercialIntegrityTest extends PedagogyTestCase
{
    use CreatesCommercialPlanningScenario;

    /** Real commits, without RefreshDatabase's enclosing rollback transaction. */
    public function refreshDatabase(): void
    {
        Artisan::call('migrate:fresh', ['--force' => true]);
        RefreshDatabaseState::$migrated = false;
        $this->beforeApplicationDestroyed(function (): void {
            Artisan::call('migrate:rollback', ['--force' => true]);
            RefreshDatabaseState::$migrated = false;
        });
    }

    public function test_direct_insert_ready_without_authorization_fails_at_commit(): void
    {
        $request = $this->draft(14);
        $attributes = $request->replicate()->getAttributes();
        $attributes['status'] = 'LISTA_PARA_PROCESAR';

        $this->assertCommitRejected(fn () => DB::table('planning_requests')->insert($attributes), 'AUTHORIZATION_REQUIRED');

        $this->assertDatabaseCount('planning_requests', 1);
    }

    public static function commercialStatuses(): array
    {
        $cases = [];
        foreach (PlanningRequestStatus::cases() as $status) {
            if (! in_array($status->value, ['BORRADOR', 'ESPERANDO_INFORMACION', 'ESPERANDO_PAGO', 'CANCELADA'])) {
                $cases[$status->value] = [$status->value];
            }
        }

        return $cases;
    }

    #[DataProvider('commercialStatuses')]
    public function test_direct_later_status_without_commerce_fails_at_commit(string $status): void
    {
        $request = $this->draft(14);

        $this->assertCommitRejected(fn () => DB::table('planning_requests')->where('id', $request->id)->update(['status' => $status]), 'AUTHORIZATION_REQUIRED');

        $this->assertSame(PlanningRequestStatus::BORRADOR, $request->fresh()->status);
    }

    public function test_parent_only_authorization_fails_at_commit(): void
    {
        $request = $this->confirm($this->draft(14));
        $period = $this->period($request);
        [$columns] = $this->commercialData($request, $period);

        $this->assertCommitRejected(fn () => DB::table('planning_requests')->where('id', $request->id)->update($columns), 'INVALID_SEGMENTS');

        $this->assertNull($request->fresh()->commercial_authorized_at);
        $this->assertDatabaseCount('usage_reservations', 0);
    }

    public function test_normal_action_commits_reservations_and_retry_does_not_duplicate(): void
    {
        $request = $this->confirm($this->draft(14));
        $this->period($request, ['human_review_required' => true, 'human_review_limit' => 8]);

        $authorized = $this->authorize($request);
        $this->authorize($request);

        $this->assertSame(PlanningRequestStatus::LISTA_PARA_PROCESAR, $authorized->status);
        $this->assertSame(2, $authorized->planning_units);
        $this->assertDatabaseCount('planning_request_segments', 2);
        $this->assertSame(['reserved', 'reserved'], DB::table('usage_reservations')->orderBy('id')->pluck('status')->all());
        $this->assertSame(1, $request->stateEvents()->where('reason', 'commercial_authorization_reserved')->count());
    }

    public function test_parent_first_then_children_and_event_commit_successfully(): void
    {
        $request = $this->confirm($this->draft(14));
        $period = $this->period($request, ['human_review_required' => true, 'human_review_limit' => 8]);

        DB::transaction(fn () => $this->writeParentFirst($request, $period));

        $this->assertSame(PlanningRequestStatus::LISTA_PARA_PROCESAR, $request->fresh()->status);
        $this->assertDatabaseCount('planning_request_segments', 2);
        $this->assertDatabaseCount('usage_reservations', 2);
        $this->assertSame(2, $request->fresh()->correction_limit_snapshot);
    }

    public static function invalidSnapshots(): array
    {
        $paths = ['subscription.id', 'subscription_period.id', 'plan_version.id', 'planning_days', 'planning_units', 'strategy', 'entitlements.human_review_required', 'segments', 'max_planning_days'];

        return array_combine($paths, array_map(fn (string $key): array => [$key], $paths));
    }

    #[DataProvider('invalidSnapshots')]
    public function test_snapshot_tampering_is_rejected_at_commit(string $path): void
    {
        $request = $this->confirm($this->draft(14));
        $period = $this->period($request);

        $this->assertCommitRejected(fn () => $this->writeParentFirst($request, $period, $path), 'PLANNING_COMMERCIAL_INVALID_');

        $this->assertNull($request->fresh()->commercial_authorized_at);
        $this->assertDatabaseCount('usage_reservations', 0);
        $this->assertDatabaseCount('planning_request_segments', 0);
    }

    public static function missingResources(): array
    {
        return ['planning' => ['planning'], 'human review' => ['human_review']];
    }

    #[DataProvider('missingResources')]
    public function test_missing_reservation_rejects_entire_authorization(string $resource): void
    {
        $request = $this->confirm($this->draft(14));
        $period = $this->period($request, ['human_review_required' => true, 'human_review_limit' => 8]);

        $this->assertCommitRejected(fn () => $this->writeParentFirst($request, $period, omitResource: $resource), 'RESERVATION_REQUIRED');

        $this->assertDatabaseCount('usage_reservations', 0);
    }

    public static function ledgerMutations(): array
    {
        return ['delete' => ['delete'], 'request' => ['planning_request_id'], 'period' => ['subscription_period_id'], 'units' => ['quantity'], 'resource' => ['resource'], 'key' => ['operation_key']];
    }

    public static function mismatchedReservations(): array
    {
        return ['quantity' => ['quantity'], 'operation key' => ['operation_key'], 'period' => ['subscription_period_id'], 'released' => ['released']];
    }

    #[DataProvider('mismatchedReservations')]
    public function test_existing_but_incorrect_reservation_cannot_authorize(string $mismatch): void
    {
        $request = $this->confirm($this->draft(14));
        $period = $this->period($request);
        $overrides = match ($mismatch) {
            'quantity' => ['quantity' => 1],
            'operation_key' => ['operation_key' => 'unrelated-operation'],
            'released' => ['status' => 'released', 'released_at' => now()],
            'subscription_period_id' => ['subscription_period_id' => app(OpenSubscriptionPeriod::class)($period->subscription, now()->addDays(31), now()->addDays(61))->id],
        };

        $this->assertCommitRejected(fn () => $this->writeParentFirst($request, $period, reservationOverrides: $overrides), 'PLANNING_RESERVATION_REQUIRED');

        $this->assertDatabaseCount('usage_reservations', 0);
        $this->assertNull($request->fresh()->commercial_authorized_at);
    }

    #[DataProvider('ledgerMutations')]
    public function test_ledger_economic_identity_and_existence_are_protected(string $column): void
    {
        $request = $this->confirm($this->draft(14));
        $this->period($request);
        $this->authorize($request);
        $reservation = DB::table('usage_reservations')->first();
        $values = ['planning_request_id' => null, 'subscription_period_id' => $reservation->subscription_period_id + 1, 'quantity' => 1, 'resource' => 'human_review', 'operation_key' => 'tampered'];

        try {
            $query = DB::table('usage_reservations')->where('id', $reservation->id);
            $column === 'delete' ? $query->delete() : $query->update([$column => $values[$column]]);
            $this->fail('Ledger identity must survive direct SQL writes.');
        } catch (\PDOException $error) {
            $this->assertStringContainsString('USAGE_RESERVATION_', $error->getMessage());
        }

        $this->assertSame((array) $reservation, (array) DB::table('usage_reservations')->first());
    }

    public function test_authorized_request_can_hold_guarded_input_revision_without_releasing_commerce(): void
    {
        $request = $this->confirm($this->draft(14));
        $this->period($request);
        $request = $this->authorize($request);

        RequestBlock::query()->create([
            'request_id' => $request->id,
            'code' => 'ai_quality_attention',
            'stage' => 'audit',
            'details' => [
                'reason' => 'AI_CORRECTION_INPUT_REVISION_REQUIRED',
                'source_version_id' => 1,
                'audit_execution_id' => 1,
            ],
            'opened_at' => now(),
        ]);

        DB::transaction(function () use ($request): void {
            DB::table('planning_requests')
                ->where('id', $request->id)
                ->update([
                    'status' => PlanningRequestStatus::ESPERANDO_INFORMACION->value,
                    'curriculum_confirmed_at' => null,
                    'curriculum_selection_fingerprint' => null,
                ]);
        });

        $editing = $request->fresh();

        $this->assertSame(PlanningRequestStatus::ESPERANDO_INFORMACION, $editing->status);
        $this->assertTrue($editing->requiresCurriculumInputRevision());
        $this->assertTrue($editing->canEditInputs());
        $this->assertNull($editing->curriculum_confirmed_at);
        $this->assertNull($editing->curriculum_selection_fingerprint);
        $this->assertNotNull($editing->commercial_authorized_at);
        $this->assertSame(2, $editing->planning_units);
        $this->assertDatabaseHas('usage_reservations', [
            'planning_request_id' => $editing->id,
            'resource' => 'planning',
        ]);
    }

    public function test_consumed_reservations_remain_commercially_valid_before_pipeline_transition(): void
    {
        $request = $this->confirm($this->draft(14));
        $this->period($request, ['human_review_required' => true, 'human_review_limit' => 8]);
        $this->authorize($request);

        DB::transaction(function (): void {
            DB::table('usage_reservations')->update(['status' => 'consumed', 'consumed_at' => now()]);
        });

        $this->assertSame(PlanningRequestStatus::LISTA_PARA_PROCESAR, $request->fresh()->status);
        $this->assertSame(['consumed', 'consumed'], DB::table('usage_reservations')->orderBy('id')->pluck('status')->all());
    }

    public function test_release_of_required_reservation_cannot_invalidate_authorized_parent(): void
    {
        $request = $this->confirm($this->draft(14));
        $this->period($request);
        $this->authorize($request);

        $this->assertCommitRejected(fn () => DB::table('usage_reservations')->update(['status' => 'released', 'released_at' => now()]), 'PLANNING_RESERVATION_REQUIRED');

        $this->assertDatabaseHas('usage_reservations', ['status' => 'reserved', 'released_at' => null]);
    }

    public function test_unallocated_reservation_can_be_released_with_timestamp(): void
    {
        $period = $this->period($this->draft(14));
        $reservation = app(ReservePlanningUnits::class)($period, UsageResource::Planning, 1, 'release-example');

        DB::table('usage_reservations')->where('id', $reservation->id)->update(['status' => 'released', 'released_at' => now()]);

        $this->assertDatabaseHas('usage_reservations', ['id' => $reservation->id, 'status' => 'released']);
        $this->assertNotNull($reservation->fresh()->released_at);
    }

    public static function periodMutations(): array
    {
        return ['snapshot' => ['entitlement_snapshot'], 'subscription' => ['subscription_id'], 'version' => ['plan_version_id']];
    }

    #[DataProvider('periodMutations')]
    public function test_period_entitlements_are_immutable_from_creation(string $column): void
    {
        $period = $this->period($this->draft(14))->refresh();
        $value = $column === 'entitlement_snapshot' ? json_encode(['planning_limit' => 999]) : $period->{$column} + 1;

        try {
            DB::table('subscription_periods')->where('id', $period->id)->update([$column => $value]);
            $this->fail('Frozen period identity must be immutable.');
        } catch (\PDOException $error) {
            $this->assertStringContainsString('SUBSCRIPTION_PERIOD_ENTITLEMENTS_IMMUTABLE', $error->getMessage());
        }

        $this->assertSame($period->entitlement_snapshot, $period->fresh()->entitlement_snapshot);
    }

    public function test_period_status_transition_preserves_authorized_history(): void
    {
        $request = $this->confirm($this->draft(14));
        $period = $this->period($request)->refresh();
        $this->authorize($request);

        DB::table('subscription_periods')->where('id', $period->id)->update(['status' => 'ended']);

        $this->assertDatabaseHas('subscription_periods', ['id' => $period->id, 'status' => 'ended']);
        $this->assertSame($period->entitlement_snapshot, $request->fresh()->calculation_snapshot['entitlements']);
    }

    public function test_pending_period_is_frozen_before_first_use_and_can_be_activated(): void
    {
        $current = $this->period($this->draft(14));
        $pending = app(OpenSubscriptionPeriod::class)($current->subscription, now()->addDays(31), now()->addDays(61))->refresh();
        $snapshot = $pending->entitlement_snapshot;

        try {
            DB::table('subscription_periods')->where('id', $pending->id)->update(['entitlement_snapshot' => json_encode([...$snapshot, 'planning_limit' => 999])]);
            $this->fail('Pending periods must already have frozen entitlements.');
        } catch (\PDOException $error) {
            $this->assertStringContainsString('SUBSCRIPTION_PERIOD_ENTITLEMENTS_IMMUTABLE', $error->getMessage());
        }
        $this->travel(32)->days();
        DB::table('subscription_periods')->where('id', $pending->id)->update(['status' => 'active']);

        $this->assertDatabaseHas('subscription_periods', ['id' => $pending->id, 'status' => 'active']);
        $this->assertSame($snapshot, $pending->fresh()->entitlement_snapshot);
    }

    private function assertCommitRejected(callable $write, string $message): void
    {
        $statementsCompleted = false;
        try {
            DB::transaction(function () use ($write, &$statementsCompleted): void {
                $write();
                $statementsCompleted = true;
            });
            $this->fail('The incomplete transaction must not commit.');
        } catch (\PDOException $error) {
            $this->assertTrue($statementsCompleted, 'Failure must occur at COMMIT, not while writing parent/children.');
            $this->assertStringContainsString($message, $error->getMessage());
        }
    }

    /** @return array{0: array<string, mixed>, 1: list<array<string, mixed>>} */
    private function commercialData(PlanningRequest $request, SubscriptionPeriod $period): array
    {
        $segments = [
            ['sequence' => 1, 'starts_on' => '2026-10-01', 'ends_on' => '2026-10-07', 'calendar_days' => 7, 'units' => 1],
            ['sequence' => 2, 'starts_on' => '2026-10-08', 'ends_on' => '2026-10-14', 'calendar_days' => 7, 'units' => 1],
        ];
        $version = $period->planVersion;
        $snapshot = [
            'schema_version' => 1, 'plan' => ['id' => $version->plan_id, 'code' => $version->plan->code, 'name' => $version->plan->name],
            'plan_version' => ['id' => $version->id, 'number' => $version->number, 'checksum' => $version->checksum],
            'subscription' => ['id' => $period->subscription_id, 'customer_id' => $request->owner_id],
            'subscription_period' => ['id' => $period->id, 'starts_at' => $period->starts_at->toIso8601String(), 'ends_at' => $period->ends_at->toIso8601String()],
            'entitlements' => $period->entitlement_snapshot, 'strategy' => 'calendar_days_v1',
            'starts_on' => '2026-10-01', 'ends_on' => '2026-10-14', 'max_planning_days' => 7,
            'planning_days' => 14, 'planning_units' => 2, 'segments' => $segments,
        ];

        return [[
            'subscription_id' => $period->subscription_id, 'subscription_period_id' => $period->id, 'plan_version_id' => $version->id,
            'planning_days' => 14, 'planning_units' => 2, 'calculation_strategy' => 'calendar_days_v1',
            'calculation_snapshot' => json_encode($snapshot), 'correction_limit_snapshot' => 2,
            'human_review_required_snapshot' => $period->entitlement_snapshot['human_review_required'],
            'commercial_authorized_at' => now(), 'status' => 'LISTA_PARA_PROCESAR',
        ], $segments];
    }

    /** @param array<string, mixed> $reservationOverrides */
    private function writeParentFirst(PlanningRequest $request, SubscriptionPeriod $period, ?string $tamperPath = null, ?string $omitResource = null, array $reservationOverrides = []): void
    {
        [$columns, $segments] = $this->commercialData($request, $period);
        if ($tamperPath !== null) {
            $snapshot = json_decode($columns['calculation_snapshot'], true);
            data_set($snapshot, $tamperPath, 'tampered');
            $columns['calculation_snapshot'] = json_encode($snapshot);
        }
        DB::table('planning_requests')->where('id', $request->id)->update($columns);
        foreach ($segments as $segment) {
            DB::table('planning_request_segments')->insert(['planning_request_id' => $request->id, ...$segment]);
        }
        $resources = $period->entitlement_snapshot['human_review_required'] ? ['planning', 'human_review'] : ['planning'];
        foreach ($resources as $resource) {
            if ($resource === $omitResource) {
                continue;
            }
            DB::table('usage_reservations')->insert([
                'subscription_period_id' => $period->id, 'planning_request_id' => $request->id,
                'resource' => $resource, 'quantity' => 2, 'status' => 'reserved', 'reserved_at' => now(),
                'operation_key' => "planning-request:{$request->id}:".($resource === 'planning' ? 'planning' : 'human-review'),
                ...$reservationOverrides,
            ]);
        }
        $request->stateEvents()->create(['from_status' => 'ESPERANDO_PAGO', 'to_status' => 'LISTA_PARA_PROCESAR', 'actor_id' => $request->owner_id, 'actor_type' => 'user', 'reason' => 'commercial_authorization_reserved']);

    }
}
