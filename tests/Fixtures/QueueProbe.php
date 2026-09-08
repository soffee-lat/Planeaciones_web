<?php
namespace Tests\Fixtures;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
class QueueProbe implements ShouldQueue {
    use Queueable;
    public function __construct(public string $key) {}
    public function handle(): void { Cache::store('database')->put($this->key, 'processed', 60); }
}
