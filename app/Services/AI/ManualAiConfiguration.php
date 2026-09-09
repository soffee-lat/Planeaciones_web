<?php

namespace App\Services\AI;

use App\Exceptions\AiPipelineException;

final class ManualAiConfiguration
{
    public function assertReady(): void
    {
        $disk = trim((string) config('ai.manual.disk', 'private'));
        $rawPrefix = trim((string) config('ai.manual.prefix', 'ai/manual'));
        $prefix = trim($rawPrefix, '/');
        $diskConfig = config('filesystems.disks.' . $disk);

        if ($disk === '' || strlen($disk) > 64 || ! is_array($diskConfig)) {
            throw new AiPipelineException('AI_MANUAL_PRIVATE_DISK_INVALID');
        }
        if (($diskConfig['visibility'] ?? null) !== 'private' || ($diskConfig['serve'] ?? false) === true) {
            throw new AiPipelineException('AI_MANUAL_PRIVATE_DISK_REQUIRED');
        }
        if ($prefix === ''
            || strlen($prefix) > 900
            || str_starts_with($rawPrefix, '/')
            || str_starts_with($rawPrefix, '\\')
            || str_contains($rawPrefix, '\\')
            || in_array('..', explode('/', $prefix), true)) {
            throw new AiPipelineException('AI_MANUAL_PREFIX_INVALID');
        }
        if ((int) config('ai.outbox.lease_seconds', 120) < 1 || (int) config('ai.outbox.retry_seconds', 30) < 1) {
            throw new AiPipelineException('AI_OUTBOX_TIMING_INVALID');
        }
    }
}
