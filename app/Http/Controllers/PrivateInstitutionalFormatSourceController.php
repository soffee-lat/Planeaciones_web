<?php

namespace App\Http\Controllers;

use App\Enums\RoleCode;
use App\Models\InstitutionalFormat;
use App\Models\User;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class PrivateInstitutionalFormatSourceController
{
    public function __invoke(InstitutionalFormat $format): Response
    {
        $user = auth()->user();
        if (! $user instanceof User
            || $user->status !== 'active'
            || ! $user->hasVerifiedEmail()
            || ! $user->hasRole(RoleCode::Customer)
            || (int) $format->owner_id !== (int) $user->id) {
            throw new AccessDeniedHttpException();
        }

        $version = $format->versions()->with('sourceFile')->orderByDesc('number')->firstOrFail();
        $file = $version->sourceFile;
        if (! $file || ! Storage::disk($file->disk)->exists($file->path)) {
            throw new NotFoundHttpException();
        }

        $bytes = Storage::disk($file->disk)->get($file->path);

        return response($bytes, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'Content-Disposition' => 'inline; filename="' . addslashes($file->original_name ?: 'formato.docx') . '"',
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
