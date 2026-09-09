<?php

namespace App\Filament\Review\Resources\Assignments\Pages;

use App\Actions\Review\ApproveHumanReview;
use App\Actions\Review\SaveHumanReview;
use App\Actions\Review\StartHumanReview;
use App\Enums\ReviewAssignmentStatus;
use App\Exceptions\HumanReviewException;
use App\Filament\Review\Resources\Assignments\ReviewAssignmentResource;
use App\Models\HumanReview;
use App\Models\ReviewerAssignment;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewReviewAssignment extends ViewRecord
{
    protected static string $resource = ReviewAssignmentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('startReview')
                ->label('Iniciar revisión')
                ->visible(fn () => $this->getRecord()->status === ReviewAssignmentStatus::Assigned && (int) $this->getRecord()->reviewer_id === (int) auth()->id())
                ->action(function (): void {
                    try {
                        app(StartHumanReview::class)->execute($this->getRecord(), auth()->user());
                        $this->record = $this->getRecord()->fresh(['review']);
                        Notification::make()->success()->title('Revisión iniciada')->send();
                    } catch (HumanReviewException $e) {
                        Notification::make()->danger()->title('No se pudo iniciar')->body($e->errorCode)->send();
                    }
                }),
            Action::make('saveChecklist')
                ->label('Guardar checklist')
                ->visible(fn () => $this->getRecord()->status === ReviewAssignmentStatus::InProgress && (int) $this->getRecord()->reviewer_id === (int) auth()->id())
                ->schema([
                    CheckboxList::make('passed_keys')
                        ->label('Criterios correctos')
                        ->options(fn () => $this->checklistOptions())
                        ->columns(1),
                    Textarea::make('general_comment')->label('Comentario general')->rows(3)->maxLength(8000),
                    Textarea::make('planning')->label('Comentarios · Planeación')->rows(2)->maxLength(4000),
                    Textarea::make('context')->label('Comentarios · Contexto de grupo')->rows(2)->maxLength(4000),
                    Textarea::make('curricular_alignment')->label('Comentarios · Alineación curricular')->rows(2)->maxLength(4000),
                    Textarea::make('pedagogical_design')->label('Comentarios · Diseño pedagógico')->rows(2)->maxLength(4000),
                    Textarea::make('sessions')->label('Comentarios · Sesiones')->rows(2)->maxLength(4000),
                    Textarea::make('assessment_plan')->label('Comentarios · Evaluación')->rows(2)->maxLength(4000),
                    Textarea::make('resources')->label('Comentarios · Recursos')->rows(2)->maxLength(4000),
                    Textarea::make('adaptation_notes')->label('Comentarios · Adecuaciones')->rows(2)->maxLength(4000),
                ])
                ->fillForm(fn () => $this->reviewFormState())
                ->action(function (array $data): void {
                    try {
                        $review = $this->currentReview();
                        if (! $review) {
                            throw new HumanReviewException('HUMAN_REVIEW_REQUIRED');
                        }
                        $passed = array_map('strval', $data['passed_keys'] ?? []);
                        $responses = [];
                        foreach ($review->checklistVersion->items as $item) {
                            $responses[$item->key] = ['passed' => in_array($item->key, $passed, true), 'comment' => null];
                        }
                        $sections = [];
                        foreach (HumanReview::SECTION_KEYS as $key) {
                            if (isset($data[$key]) && trim((string) $data[$key]) !== '') {
                                $sections[$key] = (string) $data[$key];
                            }
                        }
                        app(SaveHumanReview::class)->execute(
                            $review,
                            auth()->user(),
                            $responses,
                            $sections,
                            $data['general_comment'] ?? null,
                        );
                        Notification::make()->success()->title('Checklist guardado')->send();
                    } catch (HumanReviewException $e) {
                        Notification::make()->danger()->title('No se pudo guardar')->body($e->errorCode)->send();
                    }
                }),
            Action::make('approveReview')
                ->label('Aprobar planeación')
                ->color('success')
                ->requiresConfirmation()
                ->visible(fn () => $this->getRecord()->status === ReviewAssignmentStatus::InProgress && (int) $this->getRecord()->reviewer_id === (int) auth()->id())
                ->action(function (): void {
                    try {
                        $review = $this->currentReview();
                        if (! $review) {
                            throw new HumanReviewException('HUMAN_REVIEW_REQUIRED');
                        }
                        app(ApproveHumanReview::class)->execute($review, auth()->user());
                        Notification::make()->success()->title('Planeación aprobada')->send();
                        $this->redirect(ReviewAssignmentResource::getUrl('index'));
                    } catch (HumanReviewException $e) {
                        Notification::make()->danger()->title('No se puede aprobar')->body($e->errorCode)->send();
                    }
                }),
        ];
    }

    private function currentReview(): ?HumanReview
    {
        return HumanReview::query()
            ->with(['checklistVersion.items', 'responses.item'])
            ->where('assignment_id', $this->getRecord()->id)
            ->first();
    }

    /** @return array<string,string> */
    private function checklistOptions(): array
    {
        $review = $this->currentReview();
        return $review?->checklistVersion?->items?->mapWithKeys(fn ($item) => [$item->key => $item->label])->all() ?? [];
    }

    /** @return array<string,mixed> */
    private function reviewFormState(): array
    {
        $review = $this->currentReview();
        if (! $review) {
            return [];
        }
        $passedKeys = $review->responses->filter(fn ($response) => (bool) $response->passed)->map(fn ($response) => $response->item?->key)->filter()->values()->all();
        $state = [
            'passed_keys' => $passedKeys,
            'general_comment' => $review->general_comment,
        ];
        foreach (HumanReview::SECTION_KEYS as $key) {
            $state[$key] = $review->section_comments[$key] ?? null;
        }
        return $state;
    }

    protected function resolveRecord(int|string $key): ReviewerAssignment
    {
        $record = ReviewAssignmentResource::getEloquentQuery()->findOrFail($key);
        abort_unless(auth()->user()->can('view', $record), 404);
        return $record;
    }
}
