<?php

namespace App\Filament\Resources\PromptTemplates;

use App\Enums\PromptCategory;
use App\Filament\Resources\PromptTemplates\Pages\CreatePromptTemplate;
use App\Filament\Resources\PromptTemplates\Pages\EditPromptTemplate;
use App\Filament\Resources\PromptTemplates\Pages\ListPromptTemplates;
use App\Models\PromptTemplate;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class PromptTemplateResource extends Resource
{
    protected static ?string $model = PromptTemplate::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $navigationLabel = 'Plantillas de prompt';

    protected static ?string $modelLabel = 'Plantilla de prompt';

    protected static ?string $pluralModelLabel = 'Plantillas de prompt';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('key')
                ->label('Clave estable')
                ->required()
                ->maxLength(128)
                ->disabled(fn (?PromptTemplate $record) => $record?->versions()->exists() ?? false)
                ->helperText('Identidad técnica estable. Se congela al crear la primera versión.'),
            Select::make('category')
                ->label('Categoría')
                ->required()
                ->disabled(fn (?PromptTemplate $record) => $record?->versions()->exists() ?? false)
                ->options(collect(PromptCategory::cases())->mapWithKeys(fn (PromptCategory $case) => [$case->value => match ($case) {
                    PromptCategory::Generation => 'Generación',
                    PromptCategory::Audit => 'Auditoría',
                    PromptCategory::Correction => 'Corrección',
                    PromptCategory::DocumentAnalysis => 'Análisis de documento',
                    PromptCategory::FormatAdaptation => 'Adaptación de formato',
                }])->all()),
            TextInput::make('name')->label('Nombre')->required()->maxLength(255),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('key')->label('Clave')->searchable()->sortable(),
                TextColumn::make('name')->label('Nombre')->searchable(),
                TextColumn::make('category')->label('Categoría')->badge()->formatStateUsing(fn ($state) => $state instanceof PromptCategory ? $state->value : (string) $state),
                TextColumn::make('activeVersion.number')->label('Versión activa')->formatStateUsing(fn ($state) => $state ? 'v' . $state : '—'),
                TextColumn::make('updated_at')->label('Actualizada')->dateTime()->sortable(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()->visible(fn (PromptTemplate $record) => ! $record->versions()->exists()),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPromptTemplates::route('/'),
            'create' => CreatePromptTemplate::route('/create'),
            'edit' => EditPromptTemplate::route('/{record}/edit'),
        ];
    }
}
