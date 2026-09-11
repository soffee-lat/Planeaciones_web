<?php

namespace App\Filament\Resources\InstitutionalFormats;

use App\Enums\InstitutionalFormatKind;
use App\Filament\Resources\InstitutionalFormats\Pages\ListInstitutionalFormats;
use App\Filament\Resources\InstitutionalFormats\Pages\ViewInstitutionalFormat;
use App\Models\InstitutionalFormat;
use BackedEnum;
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
    protected static ?int $navigationSort = 25;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('kind', InstitutionalFormatKind::Institutional->value)
            ->where('owner_id', auth()->id())
            ->with(['owner', 'versions']);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('Formato')->searchable()->sortable(),
                TextColumn::make('status')
                    ->label('Estado')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => match ($state->value ?? $state) {
                        'pending_analysis' => 'Analizando',
                        'configuring' => 'Por revisar',
                        'ready' => 'Listo para usar',
                        'unsupported' => 'Necesita otro archivo',
                        'archived' => 'Archivado',
                        default => (string) ($state->value ?? $state),
                    })
                    ->color(fn ($state): string => match ($state->value ?? $state) {
                        'ready' => 'success',
                        'unsupported' => 'danger',
                        'configuring' => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('updated_at')->label('Última actualización')->dateTime('d/m/Y H:i')->sortable(),
            ])
            ->recordActions([
                ViewAction::make()->label('Revisar'),
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
