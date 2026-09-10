<?php

namespace App\Filament\Resources\PlanningRequests;

use App\Filament\Resources\PlanningRequests\Pages\ListPlanningRequests;
use App\Filament\Resources\PlanningRequests\Pages\ViewPlanningRequest;
use App\Enums\CorrectionRequestStatus;
use App\Enums\CorrectionRequestType;
use App\Models\PlanningRequest;
use App\Services\Planning\ClientCorrectionPolicy;
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
            Section::make('Corrección solicitada por el cliente')->schema([
                TextEntry::make('client_correction_reason')->label('Motivo')
                    ->state(function (PlanningRequest $record): ?string {
                        $correction = static::pendingClientCorrection($record);
                        return $correction ? (ClientCorrectionPolicy::REASONS[$correction->reason] ?? $correction->reason) : null;
                    }),
                TextEntry::make('client_correction_sections')->label('Secciones')
                    ->state(function (PlanningRequest $record): string {
                        $keys = static::pendingClientCorrection($record)?->section_keys ?? [];
                        return collect($keys)->map(fn ($key) => ClientCorrectionPolicy::SECTION_OPTIONS[$key] ?? $key)->implode(', ');
                    }),
                TextEntry::make('client_correction_description')->label('Solicitud')
                    ->state(fn (PlanningRequest $record): ?string => static::pendingClientCorrection($record)?->description)
                    ->columnSpanFull(),
                TextEntry::make('client_correction_requested_at')->label('Solicitada')
                    ->state(fn (PlanningRequest $record) => static::pendingClientCorrection($record)?->requested_at)
                    ->dateTime('d/m/Y H:i'),
            ])->columns(2)->columnSpanFull()
                ->visible(fn (PlanningRequest $record): bool => static::pendingClientCorrection($record) !== null),
        ]);
    }

    private static function pendingClientCorrection(PlanningRequest $record): ?\App\Models\CorrectionRequest
    {
        return $record->correctionRequests()
            ->where('type', CorrectionRequestType::Client->value)
            ->where('status', CorrectionRequestStatus::Requested->value)
            ->latest('id')
            ->first();
    }

    public static function getPages(): array
    {
        return ['index' => ListPlanningRequests::route('/'), 'view' => ViewPlanningRequest::route('/{record}')];
    }
}
