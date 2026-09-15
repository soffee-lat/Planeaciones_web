<?php

namespace App\Filament\App\Resources\Groups;

use App\Filament\App\Resources\Groups\Pages\CreateGroup;
use App\Filament\App\Resources\Groups\Pages\EditGroup;
use App\Filament\App\Resources\Groups\Pages\ListGroups;
use App\Models\Curriculum;
use App\Models\CurriculumVersion;
use App\Models\Grade;
use App\Models\Group;
use App\Models\School;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class GroupResource extends Resource
{
    protected static ?string $model = Group::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static ?string $modelLabel = 'Grupo';

    protected static ?string $pluralModelLabel = 'Mis grupos';

    protected static ?string $navigationLabel = 'Mis grupos';

    protected static ?int $navigationSort = 20;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('owner_id', auth()->id());
    }

    /**
     * @return array<int,int>
     */
    private static function selectableVersionIds(): array
    {
        return Curriculum::query()
            ->whereNotNull('selectable_version_id')
            ->pluck('selectable_version_id')
            ->all();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Datos del grupo')
                ->extraAttributes(['style' => 'overflow: visible;'])
                ->schema([
                Select::make('school_id')
                    ->label('Escuela')
                    ->options(fn () => School::query()
                        ->where('owner_id', auth()->id())
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all())
                    ->required()
                    ->searchable()
                    ->native(false)
                    ->helperText('Solo aparecen tus escuelas.'),

                Select::make('curriculum_version_id')
                    ->label('Currículo')
                    ->options(function () {
                        return CurriculumVersion::query()
                            ->whereIn('id', self::selectableVersionIds())
                            ->with('curriculum')
                            ->get()
                            ->mapWithKeys(fn (CurriculumVersion $v) => [
                                $v->id => ($v->curriculum?->name ?? 'Currículo') . ' — ' . $v->label,
                            ])
                            ->all();
                    })
                    ->required()
                    ->searchable()
                    ->native(false)
                    ->live()
                    ->afterStateUpdated(function (Set $set, Get $get): void {
                        // Invalidate grade if it does not belong to the new version.
                        $gradeId = $get('grade_id');
                        if ($gradeId) {
                            $ok = Grade::query()
                                ->where('id', $gradeId)
                                ->where('curriculum_version_id', $get('curriculum_version_id'))
                                ->exists();
                            if (! $ok) {
                                $set('grade_id', null);
                            }
                        }
                    })
                    ->helperText('Solo aparecen currículos publicados y seleccionables.'),

                Select::make('grade_id')
                    ->label('Grado')
                    ->options(function (Get $get) {
                        $versionId = $get('curriculum_version_id');
                        if (! $versionId) {
                            return [];
                        }
                        return Grade::query()
                            ->where('curriculum_version_id', $versionId)
                            ->orderBy('ordinal')
                            ->pluck('name', 'id')
                            ->all();
                    })
                    ->required()
                    ->helperText('Solo se muestran grados de la versión curricular seleccionada.'),

                TextInput::make('name')
                    ->label('Nombre del grupo')
                    ->required()
                    ->maxLength(120),

                TextInput::make('school_year')
                    ->label('Ciclo escolar')
                    ->required()
                    ->maxLength(32)
                    ->placeholder('Ej. 2026-2027'),
            ])->columns(2),

            Section::make('Perfil pedagógico del grupo')
                ->description('Guía la personalización de futuras planeaciones. No incluyas nombres de alumnos, CURP, teléfonos, direcciones ni diagnósticos identificables.')
                ->relationship('profile')
                ->schema([
                    Placeholder::make('privacy_notice')
                        ->label('')
                        ->content('⚠ No registres nombres, CURP, teléfonos, direcciones ni diagnósticos identificables de alumnos.')
                        ->columnSpanFull(),
                    TextInput::make('student_count')
                        ->label('Cantidad de alumnos')
                        ->numeric()
                        ->minValue(1)
                        ->maxValue(200),
                    Select::make('general_level')
                        ->label('Nivel general')
                        ->options([
                            'inicial' => 'Inicial',
                            'bajo' => 'Bajo',
                            'medio' => 'Medio',
                            'alto' => 'Alto',
                            'heterogeneo' => 'Heterogéneo',
                        ])
                        ->native(false),
                    TextInput::make('session_minutes')
                        ->label('Duración de sesión (min)')
                        ->numeric()
                        ->minValue(15)
                        ->maxValue(480),
                    Textarea::make('characteristics')->label('Características del grupo')->rows(3)->columnSpanFull(),
                    Textarea::make('difficulties')->label('Dificultades observadas')->rows(3)->columnSpanFull(),
                    Textarea::make('educational_needs')->label('Necesidades educativas')->rows(3)->columnSpanFull(),
                    Textarea::make('available_materials')->label('Materiales disponibles')->rows(2)->columnSpanFull(),
                    Textarea::make('teaching_preferences')->label('Preferencias de enseñanza')->rows(2)->columnSpanFull(),
                    Textarea::make('preferred_activities')->label('Actividades preferidas')->rows(2)->columnSpanFull(),
                    Textarea::make('restrictions')->label('Restricciones')->rows(2)->columnSpanFull(),
                    Textarea::make('management_observations')->label('Observaciones de gestión')->rows(2)->columnSpanFull(),
                    Textarea::make('required_structure')->label('Estructura requerida')->rows(2)->columnSpanFull(),
                    Textarea::make('preferred_assessment_tools')->label('Instrumentos de evaluación preferidos')->rows(2)->columnSpanFull(),
                    Textarea::make('additional_notes')->label('Notas adicionales')->rows(2)->columnSpanFull(),
                ])
                ->columns(3),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->whereNull('archived_at'))
            ->columns([
                TextColumn::make('name')->label('Grupo')->searchable()->sortable(),
                TextColumn::make('school.name')->label('Escuela')->searchable(),
                TextColumn::make('grade.name')->label('Grado'),
                TextColumn::make('curriculumVersion.curriculum.name')->label('Currículo')->toggleable(),
                TextColumn::make('school_year')->label('Ciclo'),
                IconColumn::make('archived_at')
                    ->label('Archivado')
                    ->boolean()
                    ->toggleable(),
            ])
            ->filters([
                Filter::make('archived')
                    ->label('Ver archivados')
                    ->query(fn (Builder $query) => $query->withoutGlobalScopes()->whereNotNull('archived_at')),
            ])
            ->recordActions([
                EditAction::make(),
                Action::make('archive')
                    ->label('Archivar')
                    ->icon(Heroicon::OutlinedArchiveBox)
                    ->requiresConfirmation()
                    ->visible(fn (Group $record) => ! $record->isArchived() && auth()->user()->can('archive', $record))
                    ->action(fn (Group $record) => $record->forceFill(['archived_at' => now()])->save()),
                Action::make('unarchive')
                    ->label('Reactivar')
                    ->icon(Heroicon::OutlinedArrowUturnLeft)
                    ->visible(fn (Group $record) => $record->isArchived() && auth()->user()->can('archive', $record))
                    ->action(fn (Group $record) => $record->forceFill(['archived_at' => null])->save()),
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
            'index' => ListGroups::route('/'),
            'create' => CreateGroup::route('/create'),
            'edit' => EditGroup::route('/{record}/edit'),
        ];
    }
}
