<?php

namespace App\Filament\Resources\CurriculumVersions\Tables;

use App\Actions\Curriculum\PublishCurriculumVersion;
use App\Models\CurriculumVersion;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

class CurriculumVersionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('curriculum.name')->label('Currículo')->searchable(),
                TextColumn::make('number')->label('#')->numeric()->sortable(),
                TextColumn::make('label')->label('Etiqueta')->searchable(),
                IconColumn::make('published_at')
                    ->label('Publicada')
                    ->boolean()
                    ->getStateUsing(fn (CurriculumVersion $r) => $r->published_at !== null),
                TextColumn::make('published_at')->label('Fecha publicación')->dateTime()->sortable(),
                TextColumn::make('effective_from')->label('Vigencia')->date()->sortable(),
                TextColumn::make('effective_until')->label('Hasta')->date()->sortable(),
            ])
            ->recordActions([
                EditAction::make()
                    ->visible(fn (CurriculumVersion $r) => $r->isDraft()),
                Action::make('publish')
                    ->label('Publicar')
                    ->icon('heroicon-o-check-badge')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (CurriculumVersion $r) => $r->isDraft() && Auth::user()?->can('publish', $r))
                    ->action(function (CurriculumVersion $record) {
                        try {
                            app(PublishCurriculumVersion::class)($record, Auth::user());
                            Notification::make()->title('Versión publicada')->success()->send();
                        } catch (\Throwable $e) {
                            Notification::make()->title('No se pudo publicar')->body($e->getMessage())->danger()->send();
                        }
                    }),
                DeleteAction::make()
                    ->visible(fn (CurriculumVersion $r) => $r->isDraft()),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
