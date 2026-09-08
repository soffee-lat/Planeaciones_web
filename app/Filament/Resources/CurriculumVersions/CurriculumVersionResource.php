<?php

namespace App\Filament\Resources\CurriculumVersions;

use App\Filament\Resources\CurriculumVersions\Pages\CreateCurriculumVersion;
use App\Filament\Resources\CurriculumVersions\Pages\EditCurriculumVersion;
use App\Filament\Resources\CurriculumVersions\Pages\ListCurriculumVersions;
use App\Filament\Resources\CurriculumVersions\Schemas\CurriculumVersionForm;
use App\Filament\Resources\CurriculumVersions\Tables\CurriculumVersionsTable;
use App\Models\CurriculumVersion;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class CurriculumVersionResource extends Resource
{
    protected static ?string $model = CurriculumVersion::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    public static function form(Schema $schema): Schema
    {
        return CurriculumVersionForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CurriculumVersionsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCurriculumVersions::route('/'),
            'create' => CreateCurriculumVersion::route('/create'),
            'edit' => EditCurriculumVersion::route('/{record}/edit'),
        ];
    }
}
