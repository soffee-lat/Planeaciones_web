<?php

namespace App\Http\Controllers;

use App\Actions\Validation\SubmitPilotFeedback;
use App\Models\PlanningRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class PlanningFeedbackController
{
    public function __invoke(Request $httpRequest, PlanningRequest $planningRequest, SubmitPilotFeedback $submit): RedirectResponse
    {
        $user = $httpRequest->user();
        abort_unless($user && (int) $planningRequest->owner_id === (int) $user->id, 404);
        abort_unless($planningRequest->creation_mode === 'quick', 404);

        $data = $httpRequest->validate([
            'saved_time_bucket' => ['required', 'string', Rule::in(array_keys(SubmitPilotFeedback::SAVED_TIME_OPTIONS))],
            'most_helpful' => ['required', 'string', Rule::in(array_keys(SubmitPilotFeedback::MOST_HELPFUL_OPTIONS))],
            'next_real_planning' => ['required', 'string', Rule::in(array_keys(SubmitPilotFeedback::NEXT_PLANNING_OPTIONS))],
        ]);

        try {
            $submit->execute(
                $user,
                $planningRequest,
                $data['saved_time_bucket'],
                $data['most_helpful'],
                $data['next_real_planning'],
            );
        } catch (\RuntimeException $e) {
            $message = match ($e->getMessage()) {
                'PILOT_FEEDBACK_DELIVERY_REQUIRED' => 'La planeación debe tener una entrega antes de responder estas preguntas.',
                'PILOT_FEEDBACK_ALREADY_SUBMITTED' => 'Este feedback ya fue enviado y queda congelado para el piloto.',
                default => 'No pudimos guardar el feedback del piloto. Recarga la página e inténtalo nuevamente.',
            };

            return back()->withErrors(['pilot_feedback' => $message]);
        }

        return back()->with('pilot_feedback_status', 'Gracias. Tus tres respuestas quedaron registradas para evaluar el piloto.');
    }
}
