<?php

namespace App\Filament\Resources\SubscriptionPeriods;

use App\Enums\SubscriptionPeriodStatus;
use App\Filament\Resources\SubscriptionPeriods\Pages\ListSubscriptionPeriods;
use App\Filament\Resources\SubscriptionPeriods\Pages\ViewSubscriptionPeriod;
use App\Models\SubscriptionPeriod;
use App\Services\Commerce\SubscriptionBalance;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class SubscriptionPeriodResource extends Resource
{
    protected static ?string $model = SubscriptionPeriod::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static ?string $navigationLabel = 'Periodos';

    protected static ?string $modelLabel = 'Periodo';

    protected static ?string $pluralModelLabel = 'Periodos';

    public static function canCreate(): bool
    {
        return false; // se abren mediante acciones administrativas (OpenSubscriptionPeriod).
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->label('ID')->sortable(),
                TextColumn::make('subscription.customer.name')->label('Cliente')->searchable(),
                TextColumn::make('subscription.plan.name')->label('Plan'),
                TextColumn::make('planVersion.number')
                    ->label('Versión')
                    ->formatStateUsing(fn ($state) => $state ? "v{$state}" : '—'),
                TextColumn::make('status')
                    ->label('Estado')
                    ->badge()
                    ->color(fn ($state) => match ($state) {
                        SubscriptionPeriodStatus::Active => 'success',
                        SubscriptionPeriodStatus::Pending => 'gray',
                        SubscriptionPeriodStatus::Ended => 'warning',
                        SubscriptionPeriodStatus::Cancelled => 'danger',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn ($state) => $state instanceof SubscriptionPeriodStatus ? ucfirst($state->value) : (string) $state),
                TextColumn::make('starts_at')->label('Inicio')->dateTime('Y-m-d H:i'),
                TextColumn::make('ends_at')->label('Fin')->dateTime('Y-m-d H:i'),
                TextColumn::make('planning_balance')
                    ->label('Planeaciones (disp/total)')
                    ->state(function (SubscriptionPeriod $record): string {
                        $b = (new SubscriptionBalance())->forPeriod($record)['planning'];
                        return "{$b->available()} / {$b->limit}";
                    }),
                TextColumn::make('human_review_balance')
                    ->label('Rev. humana (disp/total)')
                    ->state(function (SubscriptionPeriod $record): string {
                        $b = (new SubscriptionBalance())->forPeriod($record)['human_review'];
                        return "{$b->available()} / {$b->limit}";
                    }),
            ])
            ->defaultSort('id', 'desc')
            ->recordActions([ViewAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSubscriptionPeriods::route('/'),
            'view' => ViewSubscriptionPeriod::route('/{record}'),
        ];
    }
}
