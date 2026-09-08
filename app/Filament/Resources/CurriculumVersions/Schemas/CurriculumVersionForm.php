<?php

namespace App\Filament\Resources\CurriculumVersions\Schemas;

use App\Models\Curriculum;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class CurriculumVersionForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('curriculum_id')
                    ->label('Currículo')
                    ->options(fn () => Curriculum::query()->orderBy('code')->pluck('name', 'id'))
                    ->required()
                    ->disabledOn('edit'),
                TextInput::make('number')
                    ->label('Número de versión')
                    ->required()
                    ->numeric()
                    ->minValue(1)
                    ->disabledOn('edit'),
                TextInput::make('label')->label('Etiqueta')->required()->maxLength(255),
                TextInput::make('source_reference')->label('Fuente / referencia')->maxLength(255),
                DatePicker::make('effective_from')->label('Vigente desde'),
                DatePicker::make('effective_until')->label('Vigente hasta'),
            ]);
    }
}
