<?php

namespace App\Console\Commands;

use App\Actions\Documents\PublishPlanningDelivery;
use Illuminate\Console\Command;

class PublishPlanningDeliveryCommand extends Command
{
    protected $signature = 'documents:deliver {request_id}';
    protected $description = 'Publica la entrega privada de una planeación lista para entregar';

    public function handle(PublishPlanningDelivery $action): int
    {
        $delivery = $action->execute((int) $this->argument('request_id'));
        $this->info('Delivery #' . $delivery->id . ' publicada.');
        return self::SUCCESS;
    }
}
