<?php

namespace App\Filament\Resources\PromptVersions;

use App\Actions\AI\PublishPromptVersion;
use App\Filament\Resources\PromptVersions\Pages\CreatePromptVersion;
use App\Filament\Resources\PromptVersions\Pages\EditPromptVersion;
use App\Filament\Resources\PromptVersions\Pages\ListPromptVersions;
use App\Models\PromptTemplate;
use App\Models\PromptVersion;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

class PromptVersionResource extends Resource
{
    protected static ?string $model = PromptVersion::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $navigationLabel = 'Versiones de prompt';

    protected static ?string $modelLabel = 'Versión de prompt';

    protected static ?string $pluralModelLabel = 'Versiones de prompt';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('template_id')
                ->label('Plantilla')
                ->options(fn () => PromptTemplate::query()->orderBy('key')->pluck('name', 'id')->all())
                ->required()
                ->searchable(),
            TextInput::make('number')->label('Número de versión')->numeric()->required()->minValue(1),
            Textarea::make('body')
                ->label('Cuerpo del prompt')
                ->required()
                ->rows(14)
                ->columnSpanFull()
                ->helperText('Variables permitidas: {{variable}}. No se evalúa Blade ni código.'),
            TagsInput::make('allowed_variables')
                ->label('Variables permitidas')
                ->placeholder('input_snapshot')
                ->helperText('Nombres explícitos; cualquier placeholder fuera de esta lista impide publicar.'),
            TextInput::make('schema_version')
                ->label('Versión del contrato de salida')
                ->required()
                ->maxLength(64)
                ->default('generated_plan_draft_v1'),
            Textarea::make('output_schema')
                ->label('JSON Schema de salida')
                ->required()
                ->default(fn () => file_get_contents(resource_path('schemas/ai/generated_plan_draft_v1.schema.json')))
                ->rows(14)
                ->columnSpanFull()
                ->afterStateHydrated(function (Textarea $component, $state): void {
                    if (is_array($state)) {
                        $component->state(json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                    }
                })
                ->dehydrateStateUsing(fn ($state) => is_array($state) ? $state : json_decode((string) $state, true, 512, JSON_THROW_ON_ERROR))
                ->rule('json')
                ->helperText('Contrato estructurado; no contiene claves ni secretos.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('template.key')->label('Plantilla')->searchable()->sortable(),
                TextColumn::make('number')->label('Versión')->formatStateUsing(fn ($state) => 'v' . $state)->sortable(),
                TextColumn::make('schema_version')->label('Contrato'),
                TextColumn::make('status')
                    ->label('Estado')
                    ->badge()
                    ->getStateUsing(fn (PromptVersion $record) => $record->isPublished() ? 'Publicada' : 'Borrador')
                    ->color(fn (string $state) => $state === 'Publicada' ? 'success' : 'warning'),
                TextColumn::make('checksum')->label('Checksum')->limit(12)->placeholder('—'),
                TextColumn::make('published_at')->label('Publicación')->dateTime()->placeholder('—')->sortable(),
            ])
            ->recordActions([
                EditAction::make()->visible(fn (PromptVersion $record) => $record->isDraft()),
                Action::make('publish')
                    ->label('Publicar')
                    ->icon('heroicon-o-check-badge')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (PromptVersion $record) => $record->isDraft() && Auth::user()?->can('publish', $record))
                    ->action(function (PromptVersion $record): void {
                        try {
                            app(PublishPromptVersion::class)->execute(Auth::user(), $record);
                            Notification::make()->title('Versión publicada y activada')->success()->send();
                        } catch (\Throwable $e) {
                            Notification::make()->title('No se pudo publicar')->body($e->getMessage())->danger()->send();
                        }
                    }),
                DeleteAction::make()->visible(fn (PromptVersion $record) => $record->isDraft()),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPromptVersions::route('/'),
            'create' => CreatePromptVersion::route('/create'),
            'edit' => EditPromptVersion::route('/{record}/edit'),
        ];
    }
}
