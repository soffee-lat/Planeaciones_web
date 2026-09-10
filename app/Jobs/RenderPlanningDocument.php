<?php

namespace App\Jobs;

use App\Actions\Documents\ProcessDocumentRenderRun;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RenderPlanningDocument implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;
    public int $timeout = 120;

    public function __construct(public readonly int $renderRunId) {}

    public function handle(ProcessDocumentRenderRun $processor): void
    {
        $processor->execute($this->renderRunId);
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [30, 120, 300, 600];
    }
}
