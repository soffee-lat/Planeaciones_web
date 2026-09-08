<?php

namespace App\Filament\Resources\Curricula\Schemas;

use App\Models\CurriculumVersion;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;

class CurriculumForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('code')->label('Código')->required()->maxLength(64),
                TextInput::make('name')->label('Nombre')->required()->maxLength(255),
                TextInput::make('country_code')->label('País')->maxLength(8),
                TextInput::make('educational_level')->label('Nivel educativo')->maxLength(64),
                Textarea::make('description')->label('Descripción')->columnSpanFull(),
                Select::make('selectable_version_id')
                    ->label('Versión seleccionable (publicada)')
                    ->helperText('Solo versiones publicadas del mismo currículo.')
                    ->options(function (?Model $record) {
                        if (! $record) {
                            return [];
                        }
                        return CurriculumVersion::query()
                            ->where('curriculum_id', $record->getKey())
                            ->whereNotNull('published_at')
                            ->orderByDesc('number')
                            ->get()
                            ->mapWithKeys(fn (CurriculumVersion $v) => [$v->id => "#{$v->number} · {$v->label}"])
                            ->all();
                    })
                    ->searchable()
                    ->nullable(),
            ]);
    }
}
