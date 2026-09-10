<?php

namespace App\Console\Commands;

use App\Actions\Documents\DispatchDocumentRendering;
use App\Exceptions\DocumentRenderException;
use App\Models\PlanningRequest;
use Illuminate\Console\Command;
use Throwable;

class DispatchDocumentRenderingCommand extends Command
{
    protected $signature = 'documents:dispatch {request_id : ID de PlanningRequest aprobada o en generación documental}';

    protected $description = 'Despacha idempotentemente la generación privada DOCX/PDF de una planeación aprobada';

    public function handle(DispatchDocumentRendering $dispatch): int
    {
        $request = PlanningRequest::query()->find((int) $this->argument('request_id'));
        if (! $request) {
            $this->error('PLANNING_REQUEST_NOT_FOUND');
            return self::FAILURE;
        }

        try {
            $run = $dispatch->execute($request);
            $this->info(sprintf('Render #%d: %s; versión=%d; renderer=%s', $run->id, $run->status->value, $run->version_id, $run->renderer_version));
            return self::SUCCESS;
        } catch (DocumentRenderException $e) {
            $this->error($e->errorCode . ($e->detail ? ':' . $e->detail : ''));
            return self::FAILURE;
        } catch (Throwable $e) {
            report($e);
            $this->error('DOCUMENT_RENDER_DISPATCH_FAILED');
            return self::FAILURE;
        }
    }
}
