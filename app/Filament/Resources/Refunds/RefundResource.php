<?php

namespace App\Filament\Resources\Refunds;

use App\Enums\RefundStatus;
use App\Filament\Resources\Refunds\Pages\ListRefunds;
use App\Models\Refund;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class RefundResource extends Resource
{
    protected static ?string $model = Refund::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowUturnLeft;

    protected static ?string $navigationLabel = 'Devoluciones';

    protected static ?string $modelLabel = 'Devolución';

    protected static ?string $pluralModelLabel = 'Devoluciones';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->label('ID')->sortable(),
                TextColumn::make('payment_id')->label('Pago')->sortable(),
                TextColumn::make('payment.order_id')->label('Pedido'),
                TextColumn::make('amount_minor')
                    ->label('Monto')
                    ->state(fn (Refund $r) => number_format($r->amount_minor / 100, 2)),
                TextColumn::make('status')
                    ->label('Estado')
                    ->badge()
                    ->color(fn ($state) => match ($state) {
                        RefundStatus::Succeeded => 'success',
                        RefundStatus::Pending => 'warning',
                        default => 'danger',
                    })
                    ->formatStateUsing(fn ($state) => $state instanceof RefundStatus ? ucfirst($state->value) : (string) $state),
                TextColumn::make('reason')->label('Motivo')->limit(40),
                TextColumn::make('confirmer.name')->label('Confirmado por')->placeholder('—'),
                TextColumn::make('completed_at')->label('Completada')->dateTime('Y-m-d H:i')->placeholder('—'),
            ])
            ->defaultSort('id', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRefunds::route('/'),
        ];
    }
}
