<?php

namespace App\Console\Commands;

use App\Actions\Review\AssignReviewer;
use App\Exceptions\ReviewerAssignmentException;
use App\Models\PlanningRequest;
use Illuminate\Console\Command;

class AssignReviewerCommand extends Command
{
    protected $signature = 'review:assign {request : ID de la solicitud en REVISION_HUMANA}';

    protected $description = 'Intenta asignar atómicamente un revisor elegible a una solicitud';

    public function handle(AssignReviewer $assign): int
    {
        $request = PlanningRequest::query()->find($this->argument('request'));
        if (! $request) {
            $this->error('Solicitud no encontrada.');
            return self::FAILURE;
        }

        try {
            $assignment = $assign->execute($request);
        } catch (ReviewerAssignmentException $e) {
            $this->error($e->errorCode);
            return self::FAILURE;
        }

        if (! $assignment) {
            $this->warn('Sin revisor elegible; se registró bloqueo no_reviewer.');
            return self::SUCCESS;
        }

        $this->info("Asignación {$assignment->id} creada para revisor {$assignment->reviewer_id}.");
        return self::SUCCESS;
    }
}
