<?php

namespace App\Filament\Resources\Orders;

use App\Actions\Commerce\ActivatePaidOrder;
use App\Actions\Commerce\CancelOrder;
use App\Actions\Commerce\CreateOrder as CreateOrderAction;
use App\Actions\Commerce\RecordManualPayment;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Filament\Resources\Orders\Pages\CreateOrder;
use App\Filament\Resources\Orders\Pages\EditOrder;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Models\Order;
use App\Models\Plan;
use App\Models\PlanVersion;
use App\Models\User;
use App\Services\Commerce\Gateway\ManualPaymentInput;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Throwable;

class OrderResource extends Resource
{
    protected static ?string $model = Order::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static ?string $navigationLabel = 'Pedidos';

    protected static ?string $modelLabel = 'Pedido';

    protected static ?string $pluralModelLabel = 'Pedidos';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('customer_id')
                ->label('Cliente')
                ->options(fn () => User::query()->orderBy('name')->pluck('name', 'id'))
                ->searchable()
                ->required()
                ->disabledOn('edit'),
            Select::make('plan_id')
                ->label('Plan')
                ->options(fn () => Plan::query()->where('active', true)->orderBy('name')->pluck('name', 'id'))
                ->required()
                ->live()
                ->disabledOn('edit'),
            Select::make('plan_version_id')
                ->label('Versión de plan publicada')
                ->options(function ($get) {
                    $planId = $get('plan_id');
                    if (! $planId) {
                        return [];
                    }
                    return PlanVersion::query()
                        ->where('plan_id', $planId)
                        ->whereNotNull('published_at')
                        ->orderByDesc('number')
                        ->get()
                        ->mapWithKeys(fn (PlanVersion $v) => [$v->id => "v{$v->number} · " . number_format($v->price_minor / 100, 2) . ' ' . $v->currency])
                        ->all();
                })
                ->required()
                ->disabledOn('edit'),
            TextInput::make('concept')
                ->label('Concepto')
                ->maxLength(255)
                ->helperText('Descripción visible en el pedido. Ej. "Suscripción mensual DEMO".')
                ->disabledOn('edit'),
            TextInput::make('idempotency_key')
                ->label('Clave de idempotencia')
                ->default(fn () => (string) Str::uuid())
                ->required()
                ->maxLength(255)
                ->disabledOn('edit'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->label('ID')->sortable(),
                TextColumn::make('customer.name')->label('Cliente')->searchable(),
                TextColumn::make('plan.name')->label('Plan'),
                TextColumn::make('planVersion.number')->label('Versión')->formatStateUsing(fn ($state) => $state ? "v{$state}" : '—'),
                TextColumn::make('total_minor')
                    ->label('Total')
                    ->state(fn (Order $r) => number_format($r->total_minor / 100, 2) . ' ' . $r->currency),
                TextColumn::make('status')
                    ->label('Estado')
                    ->badge()
                    ->color(fn ($state) => match ($state) {
                        OrderStatus::Paid => 'success',
                        OrderStatus::Pending => 'warning',
                        OrderStatus::Cancelled, OrderStatus::Refunded => 'danger',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn ($state) => $state instanceof OrderStatus ? ucfirst($state->value) : (string) $state),
                TextColumn::make('activated_subscription_id')
                    ->label('Suscripción')
                    ->formatStateUsing(fn ($state) => $state ? "#{$state}" : '—'),
                TextColumn::make('paid_at')->label('Pagado')->dateTime('Y-m-d H:i')->placeholder('—'),
                TextColumn::make('created_at')->label('Creado')->dateTime('Y-m-d H:i')->sortable(),
            ])
            ->defaultSort('id', 'desc')
            ->recordActions([
                Action::make('recordManualPayment')
                    ->label('Registrar pago manual')
                    ->icon(Heroicon::OutlinedBanknotes)
                    ->color('success')
                    ->visible(fn (Order $r) => $r->status === OrderStatus::Pending)
                    ->schema([
                        TextInput::make('provider_reference')
                            ->label('Referencia (folio bancario)')
                            ->required()
                            ->maxLength(255)
                            ->helperText('Debe ser única en el sistema. Ej. folio SPEI o número de recibo.'),
                        TextInput::make('method')
                            ->label('Método')
                            ->default('transfer')
                            ->required()
                            ->helperText('transfer, cash, check, etc. (Referencial; no captura tarjeta).'),
                        DateTimePicker::make('occurred_at')
                            ->label('Fecha del pago')
                            ->default(fn () => now())
                            ->seconds(false)
                            ->required(),
                    ])
                    ->action(function (Order $r, array $data): void {
                        try {
                            (new RecordManualPayment())($r, new ManualPaymentInput(
                                amountMinor: (int) $r->total_minor,
                                currency: (string) $r->currency,
                                providerReference: (string) $data['provider_reference'],
                                method: (string) $data['method'],
                                occurredAt: Carbon::parse($data['occurred_at']),
                            ), auth()->user());
                            Notification::make()->title('Pago registrado')->success()->send();
                        } catch (Throwable $e) {
                            Notification::make()->title('No se pudo registrar')->body($e->getMessage())->danger()->send();
                        }
                    }),
                Action::make('activate')
                    ->label('Activar suscripción')
                    ->icon(Heroicon::OutlinedBolt)
                    ->color('primary')
                    ->visible(fn (Order $r) => $r->status === OrderStatus::Paid && $r->activated_subscription_id === null)
                    ->requiresConfirmation()
                    ->action(function (Order $r): void {
                        try {
                            $sub = (new ActivatePaidOrder())($r);
                            Notification::make()->title("Suscripción #{$sub->id} activada")->success()->send();
                        } catch (Throwable $e) {
                            Notification::make()->title('No se pudo activar')->body($e->getMessage())->danger()->send();
                        }
                    }),
                Action::make('cancel')
                    ->label('Cancelar pedido')
                    ->icon(Heroicon::OutlinedXCircle)
                    ->color('danger')
                    ->visible(fn (Order $r) => $r->status === OrderStatus::Pending)
                    ->requiresConfirmation()
                    ->action(function (Order $r): void {
                        try {
                            (new CancelOrder())($r, auth()->user());
                            Notification::make()->title('Pedido cancelado')->success()->send();
                        } catch (Throwable $e) {
                            Notification::make()->title('No se pudo cancelar')->body($e->getMessage())->danger()->send();
                        }
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOrders::route('/'),
            'create' => CreateOrder::route('/create'),
            'edit' => EditOrder::route('/{record}/edit'),
        ];
    }
}
