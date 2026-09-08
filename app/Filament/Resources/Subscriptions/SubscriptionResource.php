<?php

namespace App\Filament\Resources\Subscriptions;

use App\Enums\SubscriptionStatus;
use App\Filament\Resources\Subscriptions\Pages\CreateSubscription;
use App\Filament\Resources\Subscriptions\Pages\EditSubscription;
use App\Filament\Resources\Subscriptions\Pages\ListSubscriptions;
use App\Models\Plan;
use App\Models\PlanVersion;
use App\Models\Subscription;
use App\Models\User;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class SubscriptionResource extends Resource
{
    protected static ?string $model = Subscription::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedIdentification;

    protected static ?string $navigationLabel = 'Suscripciones';

    protected static ?string $modelLabel = 'Suscripción';

    protected static ?string $pluralModelLabel = 'Suscripciones';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('customer_id')
                ->label('Cliente')
                ->options(fn () => User::query()->orderBy('name')->pluck('name', 'id'))
                ->searchable()
                ->required()
                ->disabledOn('edit')
                ->helperText('Docente cliente al que se le asigna la suscripción.'),
            Select::make('plan_id')
                ->label('Plan')
                ->options(fn () => Plan::query()->where('active', true)->orderBy('name')->pluck('name', 'id'))
                ->required()
                ->live()
                ->disabledOn('edit')
                ->helperText('Plan comercial activo.'),
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
                        ->mapWithKeys(fn (PlanVersion $v) => [$v->id => "v{$v->number} · publicada " . $v->published_at?->format('Y-m-d')])
                        ->all();
                })
                ->required()
                ->disabledOn('edit')
                ->helperText('Sólo se listan versiones publicadas del plan seleccionado. Los límites (planning, revisión humana, etc.) se congelarán al abrir el primer periodo.'),
            DateTimePicker::make('starts_at')
                ->label('Inicio de la suscripción')
                ->default(fn () => now())
                ->required()
                ->seconds(false)
                ->disabledOn('edit'),
            Select::make('status')
                ->label('Estado')
                ->options(collect(SubscriptionStatus::cases())->mapWithKeys(fn ($c) => [$c->value => ucfirst(str_replace('_', ' ', $c->value))]))
                ->disabled()
                ->dehydrated(false)
                ->helperText('El estado se administra por acciones de dominio, no por edición directa.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->label('ID')->sortable(),
                TextColumn::make('customer.name')->label('Cliente')->searchable()->sortable(),
                TextColumn::make('plan.name')->label('Plan')->sortable(),
                TextColumn::make('planVersion.number')
                    ->label('Versión')
                    ->formatStateUsing(fn ($state) => $state ? "v{$state}" : '—'),
                TextColumn::make('status')
                    ->label('Estado')
                    ->badge()
                    ->color(fn ($state) => match ($state) {
                        SubscriptionStatus::Active => 'success',
                        SubscriptionStatus::PastDue => 'warning',
                        SubscriptionStatus::Pending => 'gray',
                        SubscriptionStatus::Cancelled, SubscriptionStatus::Expired => 'danger',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn ($state) => $state instanceof SubscriptionStatus ? ucfirst(str_replace('_', ' ', $state->value)) : (string) $state),
                TextColumn::make('starts_at')->label('Inicio')->dateTime('Y-m-d H:i')->placeholder('—'),
                TextColumn::make('ends_at')->label('Fin')->dateTime('Y-m-d H:i')->placeholder('—'),
                TextColumn::make('periods_count')->counts('periods')->label('Periodos'),
            ])
            ->defaultSort('id', 'desc')
            ->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSubscriptions::route('/'),
            'create' => CreateSubscription::route('/create'),
            'edit' => EditSubscription::route('/{record}/edit'),
        ];
    }
}
