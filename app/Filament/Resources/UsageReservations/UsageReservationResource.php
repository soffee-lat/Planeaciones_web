<?php

namespace App\Filament\Resources\UsageReservations;

use App\Enums\UsageReservationStatus;
use App\Enums\UsageResource;
use App\Filament\Resources\UsageReservations\Pages\ListUsageReservations;
use App\Models\UsageReservation;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class UsageReservationResource extends Resource
{
    protected static ?string $model = UsageReservation::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedListBullet;

    protected static ?string $navigationLabel = 'Reservas de uso';

    protected static ?string $modelLabel = 'Reserva';

    protected static ?string $pluralModelLabel = 'Reservas de uso';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->label('ID')->sortable(),
                TextColumn::make('subscriptionPeriod.subscription.customer.name')->label('Cliente')->searchable(),
                TextColumn::make('subscription_period_id')->label('Periodo')->sortable(),
                TextColumn::make('resource')
                    ->label('Recurso')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state instanceof UsageResource ? match ($state) {
                        UsageResource::Planning => 'Planeación',
                        UsageResource::HumanReview => 'Revisión humana',
                        UsageResource::ClientCorrection => 'Corrección',
                    } : (string) $state),
                TextColumn::make('quantity')->label('Unidades')->sortable(),
                TextColumn::make('status')
                    ->label('Estado')
                    ->badge()
                    ->color(fn ($state) => match ($state) {
                        UsageReservationStatus::Reserved => 'warning',
                        UsageReservationStatus::Consumed => 'success',
                        UsageReservationStatus::Released => 'gray',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn ($state) => $state instanceof UsageReservationStatus ? ucfirst($state->value) : (string) $state),
                TextColumn::make('operation_key')->label('Clave op.')->limit(24)->tooltip(fn (UsageReservation $r) => $r->operation_key),
                TextColumn::make('reserved_at')->label('Reservada')->dateTime('Y-m-d H:i'),
                TextColumn::make('consumed_at')->label('Consumida')->dateTime('Y-m-d H:i')->placeholder('—'),
                TextColumn::make('released_at')->label('Liberada')->dateTime('Y-m-d H:i')->placeholder('—'),
            ])
            ->defaultSort('id', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUsageReservations::route('/'),
        ];
    }
}
