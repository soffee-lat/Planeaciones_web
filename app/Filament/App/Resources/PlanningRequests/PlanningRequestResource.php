<?php

namespace App\Filament\App\Resources\PlanningRequests;

use App\Enums\PlanningRequestStatus;
use App\Enums\InstitutionalFormatStatus;
use App\Enums\InstitutionalFormatKind;
use App\Filament\App\Resources\PlanningRequests\Pages\CreatePlanningRequest;
use App\Filament\App\Resources\PlanningRequests\Pages\EditPlanningRequest;
use App\Filament\App\Resources\PlanningRequests\Pages\ListPlanningRequests;
use App\Filament\App\Resources\PlanningRequests\Pages\ViewPlanningRequest;
use App\Models\ArticulatingAxis;
use App\Models\CurricularContent;
use App\Models\Group;
use App\Models\InstitutionalFormat;
use App\Models\Pda;
use App\Models\PlanningRequest;
use App\Services\Planning\CurriculumSuggestionService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\Wizard;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;

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
        return parent::getEloquentQuery()->where('owner_id', auth()->id())->with('blocks');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Wizard::make([
                Step::make('Grupo y modalidad')
                    ->description('Elige el grupo y cómo quieres armar la planeación')
                    ->icon(Heroicon::OutlinedUsers)
                    ->schema([
                        Hidden::make('period_type'),
                        Hidden::make('period_key'),

                        Select::make('group_id')
                            ->label('Grupo')
                            ->options(fn () => static::eligibleGroupOptions())
                            ->required()
                            ->searchable()
                            ->native(false)
                            ->disabledOn('edit')
                            ->helperText('Solo grupos activos con perfil pedagógico suficiente.')
                            ->columnSpanFull(),

                        Radio::make('creation_mode')
                            ->label('Modalidad')
                            ->options([
                                'quick' => 'Rápido · Te sugerimos alineación curricular a partir del tema',
                                'advanced' => 'Avanzado · Elijo yo el catálogo paso a paso',
                            ])
                            ->default('quick')
                            ->required()
                            ->columnSpanFull(),
                    ]),

                Step::make('Datos básicos')
                    ->description('Fechas, tema y contexto de la planeación')
                    ->icon(Heroicon::OutlinedCalendarDays)
                    ->schema([
                        DatePicker::make('starts_on')
                            ->live()
                            ->label('Fecha inicial')
                            ->required()
                            ->native(false)
                            ->disabled(fn (Get $get) => in_array($get('period_type'), ['week', 'month'], true))
                            ->helperText(fn (Get $get) => in_array($get('period_type'), ['week', 'month'], true)
                                ? 'Este periodo fue delimitado desde Nueva planeación y no se modifica manualmente.'
                                : null),
                        DatePicker::make('ends_on')
                            ->live()
                            ->label('Fecha final')
                            ->required()
                            ->native(false)
                            ->afterOrEqual('starts_on')
                            ->disabled(fn (Get $get) => in_array($get('period_type'), ['week', 'month'], true)),

                        TextInput::make('project')
                            ->label('Tema o proyecto')
                            ->required()
                            ->minLength(3)
                            ->maxLength(255)
                            ->disabled(fn (Get $get) => in_array($get('period_type'), ['week', 'month'], true))
                            ->helperText(fn (Get $get) => in_array($get('period_type'), ['week', 'month'], true)
                                ? 'Se construye a partir de los temas semanales y del proyecto integrador.'
                                : null)
                            ->columnSpanFull(),

                        Textarea::make('topic')
                            ->label('Detalle del tema (opcional)')
                            ->rows(2)
                            ->disabled(fn (Get $get) => in_array($get('period_type'), ['week', 'month'], true))
                            ->helperText(fn (Get $get) => in_array($get('period_type'), ['week', 'month'], true)
                                ? 'Se genera a partir de los temas que definiste por semana.'
                                : null)
                            ->columnSpanFull(),
                        Textarea::make('book_pages')->label('Páginas / material de referencia (opcional)')->rows(2)->columnSpanFull(),
                        Textarea::make('required_activities')->label('Actividades requeridas (opcional)')->rows(2)->columnSpanFull(),
                        Textarea::make('special_events')->label('Eventos o situaciones especiales (opcional)')->rows(2)->columnSpanFull(),
                        Textarea::make('comments')->label('Observaciones adicionales (opcional)')->rows(2)->columnSpanFull(),
                        TextInput::make('period_label')
                            ->label('Etiqueta de periodo (opcional)')
                            ->maxLength(64)
                            ->disabled(fn (Get $get) => in_array($get('period_type'), ['week', 'month'], true)),

                        Placeholder::make('duration_hint')
                            ->label('Duración')
                            ->content(function (Get $get) {
                                $s = $get('starts_on');
                                $e = $get('ends_on');
                                if (! $s || ! $e) {
                                    return 'La duración informativa aparecerá al elegir ambas fechas.';
                                }
                                try {
                                    $days = (int) \Carbon\Carbon::parse($s)->diffInDays(\Carbon\Carbon::parse($e)) + 1;
                                } catch (\Throwable) {
                                    return '';
                                }
                                return 'Duración informativa: ' . $days . ' día(s) naturales. El cálculo comercial se hará al activar el plan.';
                            })
                            ->columnSpanFull(),
                    ])
                    ->columns(2),

                Step::make('Selección curricular')
                    ->description('Contenidos, PDA y ejes del grado del grupo')
                    ->icon(Heroicon::OutlinedBookOpen)
                    ->schema([
                        Placeholder::make('curriculum_intro')
                            ->hiddenLabel()
                            ->content(function (Get $get) {
                                if ($get('creation_mode') === 'quick') {
                                    return new HtmlString('<strong>Modo Rápido.</strong> Pulsa «Sugerir alineación curricular» y te propondremos contenidos, PDA y ejes a partir de las palabras clave de tu proyecto y tema. Podrás aceptar, quitar o cambiar cualquiera. Nada se confirma automáticamente.');
                                }
                                return new HtmlString('<strong>Modo Avanzado.</strong> Elige tú mismo los contenidos, PDA y ejes. Se filtran por currículo y grado del grupo.');
                            })
                            ->columnSpanFull(),

                        \Filament\Schemas\Components\Actions::make([
                            Action::make('suggest')
                                ->label('Sugerir alineación curricular')
                                ->tooltip('Búsqueda determinista por palabras clave sobre el catálogo curricular publicado. No usa IA ni servicios externos.')
                                ->icon(Heroicon::OutlinedSparkles)
                                ->color('primary')
                                ->visible(fn (Get $get) => $get('creation_mode') === 'quick')
                                ->action(function (Get $get, Set $set) {
                                    $groupId = $get('group_id');
                                    if (! $groupId) {
                                        Notification::make()->warning()->title('Selecciona un grupo primero')->send();
                                        return;
                                    }
                                    $group = Group::query()->where('owner_id', auth()->id())->find($groupId);
                                    if (! $group) {
                                        Notification::make()->danger()->title('Ese grupo no pertenece a tu cuenta')->send();
                                        return;
                                    }
                                    $result = app(CurriculumSuggestionService::class)->suggest(
                                        (int) $group->curriculum_version_id,
                                        (int) $group->grade_id,
                                        [
                                            'project' => $get('project'),
                                            'topic' => $get('topic'),
                                            'book_pages' => $get('book_pages'),
                                            'required_activities' => $get('required_activities'),
                                            'special_events' => $get('special_events'),
                                            'comments' => $get('comments'),
                                        ],
                                    );
                                    if ($result['content_ids'] === []) {
                                        Notification::make()->warning()
                                            ->title('No encontramos una coincidencia clara')
                                            ->body('Puedes buscar manualmente cambiando a modo Avanzado.')
                                            ->send();
                                        return;
                                    }
                                    $merge = function (?array $existing, array $incoming): array {
                                        $existing = array_values(array_map('intval', $existing ?? []));
                                        foreach ($incoming as $id) {
                                            if (! in_array((int) $id, $existing, true)) {
                                                $existing[] = (int) $id;
                                            }
                                        }
                                        return $existing;
                                    };
                                    $set('selected_contents', $merge($get('selected_contents'), $result['content_ids']));
                                    $set('selected_pdas', $merge($get('selected_pdas'), $result['pda_ids']));
                                    $set('selected_axes', $merge($get('selected_axes'), $result['axis_ids']));
                                    Notification::make()->success()
                                        ->title('Te sugerimos esta alineación curricular')
                                        ->body('Ajusta la selección antes de confirmar. Nada se envía todavía.')
                                        ->send();
                                }),
                        ])->columnSpanFull(),

                        Select::make('selected_contents')
                            ->label('Contenidos')
                            ->multiple()
                            ->native(false)
                            ->searchable()
                            ->preload()
                            ->options(fn (Get $get) => static::contentOptions($get('group_id')))
                            ->helperText('Se filtran por la versión y grado del grupo.')
                            ->live()
                            ->afterStateUpdated(function (Get $get, Set $set) {
                                // Al cambiar contenidos, poda los PDA cuyo contenido ya no esté seleccionado
                                // para evitar el error "validation.in" al avanzar en el wizard.
                                $allowed = array_map('intval', array_keys(static::pdaOptions($get('group_id'), $get('selected_contents') ?? [])));
                                $current = array_map('intval', $get('selected_pdas') ?? []);
                                $filtered = array_values(array_intersect($current, $allowed));
                                if ($filtered !== $current) {
                                    $set('selected_pdas', $filtered);
                                }
                            })
                            ->columnSpanFull(),

                        Select::make('selected_pdas')
                            ->label('PDA')
                            ->multiple()
                            ->native(false)
                            ->searchable()
                            ->preload()
                            ->options(fn (Get $get) => static::pdaOptions($get('group_id'), $get('selected_contents') ?? []))
                            ->helperText('Sólo PDA de los contenidos seleccionados y del grado.')
                            ->columnSpanFull(),

                        Select::make('selected_axes')
                            ->label('Ejes articuladores')
                            ->multiple()
                            ->native(false)
                            ->options(fn (Get $get) => static::axisOptions($get('group_id')))
                            ->helperText('Ejes de la versión curricular del grupo.')
                            ->columnSpanFull(),
                    ]),

                Step::make('Resumen')
                    ->description('Revisa antes de guardar el borrador')
                    ->icon(Heroicon::OutlinedCheckCircle)
                    ->schema([
                        Placeholder::make('summary')
                            ->hiddenLabel()
                            ->content(fn (Get $get) => new HtmlString(static::buildSummaryHtml($get)))
                            ->columnSpanFull(),
                        Placeholder::make('confirm_hint')
                            ->hiddenLabel()
                            ->content(new HtmlString('Al guardar quedará como <strong>borrador</strong>. Para dejarla lista para procesamiento pulsa <strong>Confirmar planeación</strong> desde la vista de edición.'))
                            ->columnSpanFull(),
                        Placeholder::make('commercial_summary')
                            ->label('Tu plan y las unidades necesarias')
                            ->content(fn (Get $get) => view('filament.app.pages.commercial-summary', [
                                'summary' => app(\App\Services\Commerce\PlanningCommercialPresentation::class)->forCustomer(auth()->user(), $get('starts_on'), $get('ends_on')),
                            ]))
                            ->columnSpanFull(),
                    ]),
            ])
                ->columnSpanFull()
                ->persistStepInQueryString('paso')
                ->skippable(fn ($livewire) => method_exists($livewire, 'getRecord') && $livewire->getRecord() !== null),
        ]);
    }

    /** @return array<int,string> */
    public static function eligibleGroupOptions(): array
    {
        return Group::query()
            ->where('owner_id', auth()->id())
            ->whereNull('archived_at')
            ->whereHas('profile', function ($q) {
                foreach (\App\Models\GroupProfile::REQUIRED_FOR_COMPLETENESS as $col) {
                    $q->whereNotNull($col);
                }
            })
            ->whereHas('curriculumVersion', fn ($q) => $q->whereNotNull('published_at'))
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    /** @return array<int,string> */
    public static function formatVersionOptions(): array
    {
        $formats = InstitutionalFormat::query()
            ->where('status', InstitutionalFormatStatus::Ready->value)
            ->where(function ($query): void {
                $query->whereNull('owner_id')->orWhere('owner_id', auth()->id());
            })
            ->with('publishedVersions')
            ->orderBy('name')
            ->get();

        $options = [];
        foreach ($formats as $format) {
            $version = $format->publishedVersions->first();
            if (! $version) {
                continue;
            }

            $suffix = $format->kind === InstitutionalFormatKind::Standard
                ? ' · estándar'
                : ' · institucional';
            $options[(int) $version->id] = $format->name . $suffix;
        }

        return $options;
    }

    /** @return array<int,string> */
    public static function contentOptions(?int $groupId): array
    {
        if (! $groupId) {
            return [];
        }
        $group = Group::query()->where('owner_id', auth()->id())->find($groupId);
        if (! $group) {
            return [];
        }
        return CurricularContent::query()
            ->where('curriculum_version_id', $group->curriculum_version_id)
            ->whereHas('pdas', fn ($q) => $q->where('grade_id', $group->grade_id))
            ->orderBy('sort_order')->orderBy('code')
            ->pluck('title', 'id')
            ->all();
    }

    /** @return array<int,string> */
    public static function pdaOptions(?int $groupId, array $contentIds): array
    {
        if (! $groupId) {
            return [];
        }
        $group = Group::query()->where('owner_id', auth()->id())->find($groupId);
        if (! $group) {
            return [];
        }
        $q = Pda::query()
            ->where('curriculum_version_id', $group->curriculum_version_id)
            ->where('grade_id', $group->grade_id);
        if (! empty($contentIds)) {
            $q->whereIn('curricular_content_id', $contentIds);
        }
        return $q->orderBy('sort_order')->orderBy('code')
            ->get(['id', 'code', 'full_text'])
            ->mapWithKeys(fn ($p) => [$p->id => $p->code . ' — ' . mb_strimwidth((string) $p->full_text, 0, 80, '…')])
            ->all();
    }

    /** @return array<int,string> */
    public static function axisOptions(?int $groupId): array
    {
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
    }

    private static function buildSummaryHtml(Get $get): string
    {
        $groupId = $get('group_id');
        $group = $groupId ? Group::query()->where('owner_id', auth()->id())->with(['grade', 'curriculumVersion.curriculum'])->find($groupId) : null;
        $mode = $get('creation_mode') === 'advanced' ? 'Avanzado' : 'Rápido';
        $starts = $get('starts_on') ?: '—';
        $ends = $get('ends_on') ?: '—';
        $project = e($get('project') ?: '—');

        $contents = static::labelsFor(CurricularContent::class, $get('selected_contents') ?? [], 'title');
        $pdas = static::labelsFor(Pda::class, $get('selected_pdas') ?? [], 'code');
        $axes = static::labelsFor(ArticulatingAxis::class, $get('selected_axes') ?? [], 'name');

        $rows = [
            'Grupo' => e($group?->name ?: '—'),
            'Grado' => e($group?->grade?->name ?: '—'),
            'Currículo' => e($group?->curriculumVersion?->curriculum?->name ?: '—'),
            'Modalidad' => $mode,
            'Fechas' => e($starts . ' → ' . $ends),
            'Tema o proyecto' => $project,
            'Contenidos' => $contents !== [] ? implode('<br>', array_map(fn ($x) => '• ' . e($x), $contents)) : '—',
            'PDA' => $pdas !== [] ? implode('<br>', array_map(fn ($x) => '• ' . e($x), $pdas)) : '—',
            'Ejes' => $axes !== [] ? implode(', ', array_map('e', $axes)) : '—',
        ];
        $html = '<dl class="space-y-2">';
        foreach ($rows as $k => $v) {
            $html .= '<div><dt class="text-sm font-semibold">' . e($k) . '</dt><dd class="text-sm">' . $v . '</dd></div>';
        }
        return $html . '</dl>';
    }

    /** @return array<int,string> */
    private static function labelsFor(string $model, array $ids, string $column): array
    {
        if (empty($ids)) {
            return [];
        }
        return $model::query()->whereIn('id', $ids)->orderBy($column)->pluck($column)->all();
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('project')->label('Proyecto')->searchable()->limit(40),
                TextColumn::make('group.name')->label('Grupo')->toggleable(),
                TextColumn::make('starts_on')->label('Inicio')->date(),
                TextColumn::make('ends_on')->label('Fin')->date(),
                TextColumn::make('creation_mode')
                    ->label('Modo')
                    ->badge()
                    ->formatStateUsing(fn (?string $s) => match ($s) {
                        'quick' => 'Rápido',
                        'advanced' => 'Avanzado',
                        default => '—',
                    })
                    ->color(fn (?string $s) => $s === 'advanced' ? 'info' : 'gray')
                    ->toggleable(),
                TextColumn::make('status')
                    ->label('Estado')
                    ->badge()
                    ->formatStateUsing(fn (PlanningRequest $record) => app(\App\Services\Commerce\PlanningCommercialPresentation::class)->status($record))
                    ->color(fn (PlanningRequest $record) => match (true) {
                        app(\App\Services\Commerce\PlanningCommercialPresentation::class)->requiresCurriculumInputRevision($record) => 'warning',
                        $record->status === PlanningRequestStatus::BORRADOR => 'gray',
                        $record->status === PlanningRequestStatus::ESPERANDO_PAGO => 'warning',
                        default => 'info',
                    }),
                TextColumn::make('updated_at')->label('Actualizada')->since()->toggleable(),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make()->visible(fn (PlanningRequest $r) => $r->canEditInputs()),
                DeleteAction::make()->visible(fn (PlanningRequest $r) => $r->isDraft()),
            ])
            ->defaultSort('updated_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPlanningRequests::route('/'),
            'create' => CreatePlanningRequest::route('/create'),
            'edit' => EditPlanningRequest::route('/{record}/edit'),
            'view' => ViewPlanningRequest::route('/{record}'),
        ];
    }
}
