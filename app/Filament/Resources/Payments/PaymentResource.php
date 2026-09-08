<?php

namespace App\Filament\Resources\Payments;

use App\Actions\Commerce\RecordManualRefund;
use App\Enums\PaymentStatus;
use App\Filament\Resources\Payments\Pages\ListPayments;
use App\Models\Payment;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Str;
use Throwable;

class PaymentResource extends Resource
{
    protected static ?string $model = Payment::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?string $navigationLabel = 'Pagos';

    protected static ?string $modelLabel = 'Pago';

    protected static ?string $pluralModelLabel = 'Pagos';

    public static function canCreate(): bool
    {
        return false; // Se registran desde el pedido con RecordManualPayment.
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->label('ID')->sortable(),
                TextColumn::make('order_id')->label('Pedido')->sortable(),
                TextColumn::make('customer.name')->label('Cliente')->searchable(),
                TextColumn::make('provider')->label('Gateway')->badge(),
                TextColumn::make('provider_reference')->label('Referencia')->limit(24)->tooltip(fn (Payment $p) => $p->provider_reference),
                TextColumn::make('method')->label('Método'),
                TextColumn::make('amount_minor')
                    ->label('Importe')
                    ->state(fn (Payment $p) => number_format($p->amount_minor / 100, 2) . ' ' . $p->currency),
                TextColumn::make('status')
                    ->label('Estado')
                    ->badge()
                    ->color(fn ($state) => match ($state) {
                        PaymentStatus::Succeeded => 'success',
                        PaymentStatus::Pending => 'warning',
                        PaymentStatus::Failed, PaymentStatus::Cancelled => 'danger',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn ($state) => $state instanceof PaymentStatus ? ucfirst($state->value) : (string) $state),
                TextColumn::make('confirmer.name')->label('Confirmado por')->placeholder('—'),
                TextColumn::make('occurred_at')->label('Fecha del pago')->dateTime('Y-m-d H:i'),
            ])
            ->defaultSort('id', 'desc')
            ->recordActions([
                Action::make('refund')
                    ->label('Registrar devolución')
                    ->icon(Heroicon::OutlinedArrowUturnLeft)
                    ->color('danger')
                    ->visible(fn (Payment $p) => $p->status === PaymentStatus::Succeeded)
                    ->schema([
                        TextInput::make('amount_minor')
                            ->label('Monto a devolver (centavos)')
                            ->numeric()
                            ->minValue(1)
                            ->required()
                            ->helperText('Ej. 12000 = 120.00. Debe ser menor o igual al saldo disponible.'),
                        TextInput::make('reason')
                            ->label('Motivo')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('provider_reference')
                            ->label('Referencia bancaria (opcional)')
                            ->maxLength(255),
                        TextInput::make('idempotency_key')
                            ->label('Clave de idempotencia')
                            ->default(fn () => (string) Str::uuid())
                            ->required()
                            ->maxLength(255),
                    ])
                    ->action(function (Payment $p, array $data): void {
                        try {
                            (new RecordManualRefund())(
                                $p,
                                (int) $data['amount_minor'],
                                (string) $data['reason'],
                                (string) $data['idempotency_key'],
                                auth()->user(),
                                isset($data['provider_reference']) ? (string) $data['provider_reference'] : null,
                            );
                            Notification::make()->title('Devolución registrada')->success()->send();
                        } catch (Throwable $e) {
                            Notification::make()->title('No se pudo registrar la devolución')->body($e->getMessage())->danger()->send();
                        }
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPayments::route('/'),
        ];
    }
}
