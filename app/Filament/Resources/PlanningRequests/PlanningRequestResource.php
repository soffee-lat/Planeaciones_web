<?php

namespace App\Filament\Resources\PlanningRequests;

use App\Filament\Resources\PlanningRequests\Pages\ListPlanningRequests;
use App\Filament\Resources\PlanningRequests\Pages\ViewPlanningRequest;
use App\Models\PlanningRequest;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class PlanningRequestResource extends Resource
{
    protected static ?string $model = PlanningRequest::class;
    protected static ?string $modelLabel = 'Autorización de planeación';
    protected static ?string $pluralModelLabel = 'Autorizaciones de planeación';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('owner.name')->label('Cliente')->searchable(),
            TextColumn::make('project')->label('Proyecto')->wrap(),
            TextColumn::make('planning_days')->label('Días'),
            TextColumn::make('planning_units')->label('Unidades'),
            TextColumn::make('commercial_authorized_at')->label('Autorizada')->dateTime(),
        ])->recordActions([ViewAction::make()]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Autorización comercial · Solo lectura')->schema([
                TextEntry::make('owner.name')->label('Cliente'),
                TextEntry::make('project')->label('Proyecto'),
                TextEntry::make('status')->label('Estado')->formatStateUsing(fn ($state): string => $state->value),
                TextEntry::make('commercial_authorized_at')->label('Autorizada')->dateTime(),
                TextEntry::make('planning_days')->label('Días'),
                TextEntry::make('planning_units')->label('Unidades'),
                TextEntry::make('calculation_strategy')->label('Estrategia'),
                TextEntry::make('correction_limit_snapshot')->label('Rondas por solicitud'),
                TextEntry::make('human_review_required_snapshot')->label('Revisión humana')->formatStateUsing(fn ($state): string => $state ? 'Sí' : 'No'),
                TextEntry::make('commercial_details')->label('Snapshot comercial y segmentos')
                    ->state(fn (PlanningRequest $record): string => $record->calculation_snapshot ? json_encode($record->calculation_snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) : 'Pendiente de autorizar')
                    ->extraAttributes(['class' => 'whitespace-pre-wrap break-all'])->columnSpanFull(),
            ])->columns(2)->columnSpanFull(),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListPlanningRequests::route('/'), 'view' => ViewPlanningRequest::route('/{record}')];
    }
}
