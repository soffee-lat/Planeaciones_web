<?php

namespace App\Console\Commands;

use App\Actions\Documents\PurgeExpiredDocumentFiles;
use Illuminate\Console\Command;

class PurgeExpiredDocumentFilesCommand extends Command
{
    protected $signature = 'documents:purge-expired {--limit=500}';
    protected $description = 'Elimina bytes expirados conservando metadatos e historial de entrega';

    public function handle(PurgeExpiredDocumentFiles $action): int
    {
        $result = $action->execute((int) $this->option('limit'));
        $this->info("Purged: {$result['purged']}; failed: {$result['failed']}");
        return $result['failed'] === 0 ? self::SUCCESS : self::FAILURE;
    }
}
