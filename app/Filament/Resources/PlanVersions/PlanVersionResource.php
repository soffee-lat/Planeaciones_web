<?php

namespace App\Filament\Resources\PlanVersions;

use App\Actions\Plans\PublishPlanVersion;
use App\Filament\Resources\PlanVersions\Pages\CreatePlanVersion;
use App\Filament\Resources\PlanVersions\Pages\EditPlanVersion;
use App\Filament\Resources\PlanVersions\Pages\ListPlanVersions;
use App\Models\Plan;
use App\Models\PlanVersion;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

class PlanVersionResource extends Resource
{
    protected static ?string $model = PlanVersion::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $navigationLabel = 'Versiones de plan';

    protected static ?string $modelLabel = 'Versión de plan';

    protected static ?string $pluralModelLabel = 'Versiones de plan';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('plan_id')
                ->label('Plan')
                ->options(fn () => Plan::query()->orderBy('code')->pluck('name', 'id')->all())
                ->required()
                ->searchable(),
            TextInput::make('number')->label('Número de versión')->numeric()->required()->minValue(1)
                ->helperText('Correlativo por plan; junto al plan forma la clave única.'),
            TextInput::make('price_minor')->label('Precio (en centavos)')->numeric()->required()->minValue(0)
                ->helperText('Ej. 12345 = 123.45 en la moneda indicada.'),
            TextInput::make('currency')->label('Moneda (ISO 4217)')->required()->maxLength(8)->default('MXN'),
            Select::make('interval_unit')->label('Unidad de intervalo')->required()->options(['month' => 'Mes', 'year' => 'Año']),
            TextInput::make('interval_count')->label('Cantidad de intervalo')->numeric()->required()->minValue(1)->default(1)
                ->helperText('Ej. 1 mes, 12 meses, 1 año.'),
            TextInput::make('max_planning_days')->label('Máximo de días por unidad (M)')->numeric()->required()->minValue(1)
                ->helperText('M en la fórmula U = ceil(D / M).'),
            TextInput::make('planning_limit')->label('Planeaciones (unidades) por periodo')->numeric()->required()->minValue(1),
            TextInput::make('human_review_limit')->label('Unidades con revisión humana por periodo')->numeric()->required()->minValue(0)->default(0)
                ->helperText('0 si el plan no incluye revisión humana.'),
            TextInput::make('correction_limit')->label('Correcciones por solicitud')->numeric()->required()->minValue(0)->default(0)
                ->helperText('No se multiplica por U.'),
            TextInput::make('group_limit')->label('Grupos activos por suscripción')->numeric()->required()->minValue(1)->default(1),
            TextInput::make('correction_window_days')->label('Días para solicitar corrección')->numeric()->minValue(0)->default(0),
            TextInput::make('sla_hours')->label('SLA (horas)')->numeric()->minValue(0)->default(0),
            Toggle::make('human_review_required')->label('Requiere revisión humana')->default(false)
                ->helperText('Si se activa, el límite de revisión humana debe ser mayor a 0.'),
            DatePicker::make('effective_from')->label('Vigencia desde'),
            DatePicker::make('effective_until')->label('Vigencia hasta'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('plan.name')->label('Plan')->searchable(),
                TextColumn::make('number')->label('#')->numeric()->sortable(),
                TextColumn::make('price_minor')->label('Precio')->formatStateUsing(fn ($state, PlanVersion $r) => number_format($state / 100, 2) . ' ' . $r->currency),
                TextColumn::make('max_planning_days')->label('M'),
                TextColumn::make('planning_limit')->label('Límite'),
                IconColumn::make('human_review_required')->label('Revisión humana')->boolean(),
                TextColumn::make('status')
                    ->label('Estado')
                    ->badge()
                    ->getStateUsing(fn (PlanVersion $r) => $r->isPublished() ? 'Publicada' : 'Borrador')
                    ->color(fn (string $state) => $state === 'Publicada' ? 'success' : 'warning'),
                TextColumn::make('published_at')->label('Publicación')->dateTime()->sortable()->placeholder('—'),
            ])
            ->recordActions([
                EditAction::make()->visible(fn (PlanVersion $r) => $r->isDraft()),
                Action::make('publish')
                    ->label('Publicar')
                    ->icon('heroicon-o-check-badge')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (PlanVersion $r) => $r->isDraft() && Auth::user()?->can('publish', $r))
                    ->action(function (PlanVersion $record) {
                        try {
                            app(PublishPlanVersion::class)($record, Auth::user());
                            Notification::make()->title('Versión publicada')->success()->send();
                        } catch (\Throwable $e) {
                            Notification::make()->title('No se pudo publicar')->body($e->getMessage())->danger()->send();
                        }
                    }),
                DeleteAction::make()->visible(fn (PlanVersion $r) => $r->isDraft()),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPlanVersions::route('/'),
            'create' => CreatePlanVersion::route('/create'),
            'edit' => EditPlanVersion::route('/{record}/edit'),
        ];
    }
}
