<?php

namespace App\Filament\Review\Resources\Assignments;

use App\Enums\ReviewAssignmentStatus;
use App\Enums\RoleCode;
use App\Filament\Review\Resources\Assignments\Pages\ListReviewAssignments;
use App\Filament\Review\Resources\Assignments\Pages\ViewReviewAssignment;
use App\Models\ReviewerAssignment;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ReviewAssignmentResource extends Resource
{
    protected static ?string $model = ReviewerAssignment::class;
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;
    protected static ?string $modelLabel = 'Revisión asignada';
    protected static ?string $pluralModelLabel = 'Mis revisiones';
    protected static ?string $navigationLabel = 'Mis revisiones';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with(['request.grade', 'review']);
        $user = auth()->user();
        if (! $user?->hasRole(RoleCode::Administrator)) {
            $query->where('reviewer_id', auth()->id())
                ->whereIn('status', [ReviewAssignmentStatus::Assigned->value, ReviewAssignmentStatus::InProgress->value]);
        }

        return $query;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('request.project')->label('Planeación')->wrap()->searchable(),
                TextColumn::make('request.grade.name')->label('Grado'),
                TextColumn::make('status')->label('Estado')->badge()->formatStateUsing(fn ($state) => $state instanceof ReviewAssignmentStatus ? match ($state) {
                    ReviewAssignmentStatus::Assigned => 'Asignada',
                    ReviewAssignmentStatus::InProgress => 'En revisión',
                    ReviewAssignmentStatus::Completed => 'Completada',
                    ReviewAssignmentStatus::Reassigned => 'Reasignada',
                    ReviewAssignmentStatus::Cancelled => 'Cancelada',
                } : (string) $state),
                TextColumn::make('due_at')->label('Vence')->dateTime('d/m/Y H:i'),
                TextColumn::make('units_snapshot')->label('U'),
            ])
            ->defaultSort('due_at')
            ->recordActions([ViewAction::make()]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Trabajo asignado')->schema([
                TextEntry::make('request.project')->label('Tema o proyecto'),
                TextEntry::make('request.grade.name')->label('Grado'),
                TextEntry::make('due_at')->label('Fecha límite')->dateTime('d/m/Y H:i'),
                TextEntry::make('status')->label('Estado')->formatStateUsing(fn ($state) => $state instanceof ReviewAssignmentStatus ? $state->value : (string) $state),
                TextEntry::make('review.status')->label('Revisión')->placeholder('Aún no iniciada')->formatStateUsing(fn ($state) => $state?->value ?? (string) $state),
            ])->columns(2)->columnSpanFull(),
            Section::make('Versión a revisar')->description('Contenido pedagógico de la versión exacta asignada; no incluye pagos ni datos de cuenta del cliente.')->schema([
                TextEntry::make('canonical_plan')
                    ->hiddenLabel()
                    ->state(function (ReviewerAssignment $record): string {
                        $content = $record->request?->document?->currentVersion?->content;
                        return $content ? json_encode($content, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : 'Versión no disponible.';
                    })
                    ->extraAttributes(['class' => 'whitespace-pre-wrap break-words font-mono text-xs'])
                    ->columnSpanFull(),
            ])->columnSpanFull(),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListReviewAssignments::route('/'),
            'view' => ViewReviewAssignment::route('/{record}'),
        ];
    }
}
