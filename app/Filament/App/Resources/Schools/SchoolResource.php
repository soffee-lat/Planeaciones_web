<?php

namespace App\Filament\App\Resources\Schools;

use App\Enums\SchoolType;
use App\Filament\App\Resources\Schools\Pages\CreateSchool;
use App\Filament\App\Resources\Schools\Pages\EditSchool;
use App\Filament\App\Resources\Schools\Pages\ListSchools;
use App\Models\School;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class SchoolResource extends Resource
{
    protected static ?string $model = School::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingLibrary;

    protected static ?string $modelLabel = 'Escuela';

    protected static ?string $pluralModelLabel = 'Mis escuelas';

    protected static ?string $navigationLabel = 'Mis escuelas';

    protected static ?int $navigationSort = 10;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('owner_id', auth()->id());
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->label('Nombre de la escuela')
                ->required()
                ->maxLength(255),
            Select::make('school_type')
                ->label('Tipo de escuela')
                ->options(SchoolType::options())
                ->required()
                ->native(false),
            TextInput::make('state')
                ->label('Estado')
                ->required()
                ->maxLength(128),
            TextInput::make('municipality')
                ->label('Municipio')
                ->maxLength(128),
            Textarea::make('notes')
                ->label('Notas')
                ->rows(3)
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('Escuela')->searchable()->sortable(),
                TextColumn::make('school_type')
                    ->label('Tipo')
                    ->formatStateUsing(fn ($state): string => SchoolType::options()[$state instanceof SchoolType ? $state->value : (string) $state] ?? (string) $state),
                TextColumn::make('state')->label('Estado')->searchable(),
                TextColumn::make('municipality')->label('Municipio')->toggleable(),
                TextColumn::make('created_at')->label('Creada')->dateTime('d M Y')->toggleable(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSchools::route('/'),
            'create' => CreateSchool::route('/create'),
            'edit' => EditSchool::route('/{record}/edit'),
        ];
    }
}
