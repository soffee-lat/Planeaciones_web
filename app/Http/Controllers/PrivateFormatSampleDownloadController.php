<?php

namespace App\Http\Controllers;

use App\Enums\RoleCode;
use App\Models\FormatVersionSample;
use App\Models\StoredFile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class PrivateFormatSampleDownloadController
{
    public function __invoke(Request $request, FormatVersionSample $sample, StoredFile $file): StreamedResponse
    {
        $sample->loadMissing('formatVersion.format');
        $user = $request->user();
        abort_unless($user && $user->status === 'active' && $user->hasVerifiedEmail() && $user->hasRole(RoleCode::Customer)
            && (int) $sample->formatVersion?->format?->owner_id === (int) $user->id, 403);
        abort_unless(in_array((int) $file->id, [(int) $sample->docx_file_id, (int) $sample->pdf_file_id], true), 404);
        abort_if($file->purged_at !== null, 410);
        abort_unless((int) $file->owner_id === (int) $user->id, 403);
        $storage = Storage::disk($file->disk);
        abort_unless($storage->exists($file->path), 404);
        return $storage->download($file->path, $file->original_name, [
            'Content-Type' => $file->detected_mime,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store, max-age=0',
        ]);
    }
}
