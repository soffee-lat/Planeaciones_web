<?php

namespace App\Filament\Resources\InstitutionalFormats;

use App\Enums\InstitutionalFormatKind;
use App\Filament\Resources\InstitutionalFormats\Pages\ListInstitutionalFormats;
use App\Filament\Resources\InstitutionalFormats\Pages\ViewInstitutionalFormat;
use App\Models\InstitutionalFormat;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class InstitutionalFormatResource extends Resource
{
    protected static ?string $model = InstitutionalFormat::class;
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;
    protected static ?string $navigationLabel = 'Mis formatos';
    protected static ?string $modelLabel = 'Formato';
    protected static ?string $pluralModelLabel = 'Mis formatos';
    protected static ?int $navigationSort = 50;

    public static function form(Schema $schema): Schema { return $schema->components([]); }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('kind', InstitutionalFormatKind::Institutional->value)
            ->where('owner_id', auth()->id())
            ->with(['owner', 'versions']);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->label('Formato')->searchable()->sortable(),
            TextColumn::make('status')->label('Estado')->badge()->formatStateUsing(fn ($state): string => match ($state->value ?? $state) {
                'pending_analysis' => 'Pendiente de análisis',
                'configuring' => 'Configurando',
                'ready' => 'Listo',
                'unsupported' => 'No compatible',
                'archived' => 'Archivado',
                default => (string) ($state->value ?? $state),
            })->color(fn ($state): string => match ($state->value ?? $state) {
                'ready' => 'success',
                'unsupported' => 'danger',
                'configuring' => 'warning',
                default => 'gray',
            }),
            TextColumn::make('versions_count')->counts('versions')->label('Versiones'),
            TextColumn::make('updated_at')->label('Actualizado')->dateTime('d/m/Y H:i')->sortable(),
        ])->recordActions([
            Action::make('designer')
                ->label('Diseñador visual')
                ->icon('heroicon-o-cursor-arrow-rays')
                ->color('primary')
                ->url(fn (InstitutionalFormat $record): string => route('institutional-formats.designer', $record)),
            ViewAction::make()->label('Resumen'),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListInstitutionalFormats::route('/'),
            'view' => ViewInstitutionalFormat::route('/{record}'),
        ];
    }
}
