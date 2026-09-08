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
            TextInput::make('number')->label('Número')->numeric()->required()->minValue(1),
            TextInput::make('price_minor')->label('Precio (centavos)')->numeric()->required()->minValue(0),
            TextInput::make('currency')->label('Moneda ISO 4217')->required()->maxLength(8)->default('MXN'),
            Select::make('interval_unit')->label('Intervalo')->required()->options(['month' => 'month', 'year' => 'year']),
            TextInput::make('interval_count')->label('Cantidad de intervalo')->numeric()->required()->minValue(1)->default(1),
            TextInput::make('max_planning_days')->label('max_planning_days (M)')->numeric()->required()->minValue(1),
            TextInput::make('planning_limit')->label('planning_limit')->numeric()->required()->minValue(1),
            TextInput::make('human_review_limit')->label('human_review_limit')->numeric()->required()->minValue(0)->default(0),
            TextInput::make('correction_limit')->label('correction_limit')->numeric()->required()->minValue(0)->default(0),
            TextInput::make('group_limit')->label('group_limit')->numeric()->required()->minValue(1)->default(1),
            TextInput::make('correction_window_days')->label('correction_window_days')->numeric()->minValue(0)->default(0),
            TextInput::make('sla_hours')->label('sla_hours')->numeric()->minValue(0)->default(0),
            Toggle::make('human_review_required')->label('Requiere revisión humana')->default(false),
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
                IconColumn::make('published_at')->label('Publicada')->boolean()->getStateUsing(fn (PlanVersion $r) => $r->isPublished()),
                TextColumn::make('published_at')->label('Publicación')->dateTime()->sortable(),
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
