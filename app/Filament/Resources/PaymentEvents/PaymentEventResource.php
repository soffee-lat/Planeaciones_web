<?php

namespace App\Filament\Resources\PaymentEvents;

use App\Enums\PaymentEventStatus;
use App\Filament\Resources\PaymentEvents\Pages\ListPaymentEvents;
use App\Models\PaymentEvent;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class PaymentEventResource extends Resource
{
    protected static ?string $model = PaymentEvent::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBell;

    protected static ?string $navigationLabel = 'Eventos de pago';

    protected static ?string $modelLabel = 'Evento de pago';

    protected static ?string $pluralModelLabel = 'Eventos de pago';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->label('ID')->sortable(),
                TextColumn::make('provider')->label('Gateway')->badge(),
                TextColumn::make('event_id')->label('Evento')->limit(28)->tooltip(fn (PaymentEvent $e) => $e->event_id),
                TextColumn::make('order_id')->label('Pedido')->placeholder('—'),
                TextColumn::make('payment_id')->label('Pago')->placeholder('—'),
                TextColumn::make('status')
                    ->label('Estado')
                    ->badge()
                    ->color(fn ($state) => match ($state) {
                        PaymentEventStatus::Processed => 'success',
                        PaymentEventStatus::Received => 'gray',
                        PaymentEventStatus::Ignored => 'warning',
                        PaymentEventStatus::Failed => 'danger',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn ($state) => $state instanceof PaymentEventStatus ? ucfirst($state->value) : (string) $state),
                TextColumn::make('error_code')->label('Error')->placeholder('—'),
                TextColumn::make('received_at')->label('Recibido')->dateTime('Y-m-d H:i'),
                TextColumn::make('processed_at')->label('Procesado')->dateTime('Y-m-d H:i')->placeholder('—'),
            ])
            ->defaultSort('id', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPaymentEvents::route('/'),
        ];
    }
}
