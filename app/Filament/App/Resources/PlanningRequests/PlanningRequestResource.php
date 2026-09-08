<?php

namespace App\Filament\App\Resources\PlanningRequests;

use App\Enums\PlanningRequestStatus;
use App\Filament\App\Resources\PlanningRequests\Pages\CreatePlanningRequest;
use App\Filament\App\Resources\PlanningRequests\Pages\EditPlanningRequest;
use App\Filament\App\Resources\PlanningRequests\Pages\ListPlanningRequests;
use App\Filament\App\Resources\PlanningRequests\Pages\ViewPlanningRequest;
use App\Models\ArticulatingAxis;
use App\Models\CurricularContent;
use App\Models\Group;
use App\Models\Pda;
use App\Models\PlanningRequest;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class PlanningRequestResource extends Resource
{
    protected static ?string $model = PlanningRequest::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static ?string $modelLabel = 'Planeación';

    protected static ?string $pluralModelLabel = 'Mis planeaciones';

    protected static ?string $navigationLabel = 'Mis planeaciones';

    protected static ?int $navigationSort = 30;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('owner_id', auth()->id());
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Datos de la solicitud')->schema([
                Select::make('group_id')
                    ->label('Grupo')
                    ->options(fn () => Group::query()
                        ->where('owner_id', auth()->id())
                        ->whereNull('archived_at')
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all())
                    ->required()
                    ->searchable()
                    ->native(false)
                    ->disabledOn('edit')
                    ->helperText('Solo tus grupos activos. Al elegir se fija currículo y grado del grupo.'),

                DatePicker::make('starts_on')->label('Fecha inicial')->required()->native(false),
                DatePicker::make('ends_on')->label('Fecha final')->required()->native(false)->afterOrEqual('starts_on'),

                TextInput::make('project')->label('Tema o proyecto')->required()->maxLength(255)->columnSpanFull(),
                Textarea::make('topic')->label('Detalle del tema')->rows(2)->columnSpanFull(),
                Textarea::make('book_pages')->label('Páginas / material de referencia')->rows(2)->columnSpanFull(),
                Textarea::make('required_activities')->label('Actividades requeridas')->rows(2)->columnSpanFull(),
                Textarea::make('special_events')->label('Eventos especiales')->rows(2)->columnSpanFull(),
                Textarea::make('requested_assessment')->label('Evaluación solicitada')->rows(2)->columnSpanFull(),
                Textarea::make('pedagogical_notes')->label('Notas pedagógicas')->rows(2)->columnSpanFull(),
                Textarea::make('comments')->label('Comentarios')->rows(2)->columnSpanFull(),
                TextInput::make('period_label')->label('Etiqueta de periodo')->maxLength(64),
            ])->columns(2),

            Section::make('Selección curricular')
                ->description('Elige contenidos, PDA y ejes del grado y currículo del grupo. Estas listas se llenarán cuando el grupo esté seleccionado.')
                ->schema([
                    Placeholder::make('curriculum_note')
                        ->label('')
                        ->content('Al menos un contenido y un PDA. Cada contenido seleccionado requiere ≥ 1 PDA. Se validará server-side al confirmar.')
                        ->columnSpanFull(),

                    Select::make('selected_contents')
                        ->label('Contenidos')
                        ->multiple()
                        ->native(false)
                        ->searchable()
                        ->options(function (Get $get) {
                            $groupId = $get('group_id');
                            if (! $groupId) {
                                return [];
                            }
                            $group = Group::query()->where('owner_id', auth()->id())->find($groupId);
                            if (! $group) {
                                return [];
                            }
                            return CurricularContent::query()
                                ->where('curriculum_version_id', $group->curriculum_version_id)
                                ->orderBy('sort_order')
                                ->orderBy('code')
                                ->pluck('title', 'id')
                                ->all();
                        })
                        ->columnSpanFull(),

                    Select::make('selected_pdas')
                        ->label('PDA')
                        ->multiple()
                        ->native(false)
                        ->searchable()
                        ->options(function (Get $get) {
                            $groupId = $get('group_id');
                            if (! $groupId) {
                                return [];
                            }
                            $group = Group::query()->where('owner_id', auth()->id())->find($groupId);
                            if (! $group) {
                                return [];
                            }
                            return Pda::query()
                                ->where('curriculum_version_id', $group->curriculum_version_id)
                                ->where('grade_id', $group->grade_id)
                                ->orderBy('sort_order')
                                ->orderBy('code')
                                ->get(['id', 'code', 'full_text'])
                                ->mapWithKeys(fn ($p) => [$p->id => $p->code . ' — ' . mb_strimwidth((string) $p->full_text, 0, 80, '…')])
                                ->all();
                        })
                        ->columnSpanFull(),

                    Select::make('selected_axes')
                        ->label('Ejes articuladores')
                        ->multiple()
                        ->native(false)
                        ->options(function (Get $get) {
                            $groupId = $get('group_id');
                            if (! $groupId) {
                                return [];
                            }
                            $group = Group::query()->where('owner_id', auth()->id())->find($groupId);
                            if (! $group) {
                                return [];
                            }
                            return ArticulatingAxis::query()
                                ->where('curriculum_version_id', $group->curriculum_version_id)
                                ->orderBy('sort_order')
                                ->pluck('name', 'id')
                                ->all();
                        })
                        ->columnSpanFull(),
                ])
                ->columns(1),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('project')->label('Proyecto')->searchable()->limit(40),
                TextColumn::make('group.name')->label('Grupo')->toggleable(),
                TextColumn::make('starts_on')->label('Inicio')->date(),
                TextColumn::make('ends_on')->label('Fin')->date(),
                TextColumn::make('status')
                    ->label('Estado')
                    ->badge()
                    ->formatStateUsing(fn (PlanningRequestStatus $state) => match ($state) {
                        PlanningRequestStatus::BORRADOR => 'Borrador',
                        PlanningRequestStatus::ESPERANDO_PAGO => 'Confirmada · Esperando plan/pago',
                        default => $state->value,
                    })
                    ->color(fn (PlanningRequestStatus $state) => match ($state) {
                        PlanningRequestStatus::BORRADOR => 'gray',
                        PlanningRequestStatus::ESPERANDO_PAGO => 'warning',
                        default => 'info',
                    }),
                TextColumn::make('input_revision')->label('Rev. entrada')->toggleable(),
                TextColumn::make('updated_at')->label('Actualizada')->since()->toggleable(),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make()->visible(fn (PlanningRequest $r) => $r->isDraft()),
                DeleteAction::make()->visible(fn (PlanningRequest $r) => $r->isDraft()),
            ])
            ->defaultSort('updated_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPlanningRequests::route('/'),
            'create' => CreatePlanningRequest::route('/create'),
            'view' => ViewPlanningRequest::route('/{record}'),
            'edit' => EditPlanningRequest::route('/{record}/edit'),
        ];
    }
}
