<?php

namespace App\Services\Documents;

use App\Enums\FileCategory;
use App\Enums\FileScanStatus;
use App\Models\DeliveryDownload;
use App\Models\PlanningDelivery;
use App\Models\StoredFile;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class PrivateDeliveryDownloadService
{
    public function response(User $user, PlanningDelivery $delivery, StoredFile $file): StreamedResponse
    {
        abort_unless($user->status === 'active', 403);
        $delivery->loadMissing('request');
        abort_unless($delivery->request && (int) $delivery->request->owner_id === (int) $user->id, 403);
        abort_unless($file->category === FileCategory::Result && $file->scan_status === FileScanStatus::Clean, 404);
        abort_unless((int) $file->owner_id === (int) $user->id && (int) $file->request_id === (int) $delivery->request_id, 404);
        abort_unless(DB::table('delivery_files')->where('delivery_id', $delivery->id)->where('file_id', $file->id)->exists(), 404);

        if ($file->purged_at !== null || ($file->retention_until !== null && $file->retention_until->isPast())) {
            abort(410, 'DELIVERY_FILE_EXPIRED');
        }
        abort_unless(Storage::disk($file->disk)->exists($file->path), 410, 'DELIVERY_FILE_MISSING');

        DeliveryDownload::query()->create([
            'delivery_id' => $delivery->id,
            'file_id' => $file->id,
            'user_id' => $user->id,
            'downloaded_at' => now(),
        ]);

        return Storage::disk($file->disk)->download($file->path, $file->original_name, [
            'Content-Type' => $file->detected_mime,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store, max-age=0',
        ]);
    }
}
