<?php

namespace App\Actions\Documents;

use App\Enums\FileCategory;
use App\Exceptions\DocumentDeliveryException;
use App\Models\StoredFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

final class PurgeExpiredDocumentFiles
{
    /** @return array{purged:int,failed:int} */
    public function execute(int $limit = 500): array
    {
        $purged = 0;
        $failed = 0;
        $ids = StoredFile::query()
            ->where('category', FileCategory::Result->value)
            ->whereNull('purged_at')
            ->whereNotNull('retention_until')
            ->where('retention_until', '<=', now())
            ->orderBy('id')
            ->limit(max(1, $limit))
            ->pluck('id');

        foreach ($ids as $id) {
            try {
                DB::transaction(function () use ($id, &$purged): void {
                    $file = StoredFile::query()->whereKey($id)->lockForUpdate()->first();
                    if (! $file || $file->purged_at !== null || $file->retention_until === null || $file->retention_until->isFuture()) {
                        return;
                    }
                    $disk = Storage::disk($file->disk);
                    if ($disk->exists($file->path) && ! $disk->delete($file->path)) {
                        throw new DocumentDeliveryException('DOCUMENT_RETENTION_DELETE_FAILED');
                    }
                    $file->forceFill(['purged_at' => now()])->save();
                    $purged++;
                });
            } catch (\Throwable $e) {
                report($e);
                $failed++;
            }
        }

        return compact('purged', 'failed');
    }
}
