<?php

namespace App\Http\Controllers;

use App\Actions\Documents\RenderInstitutionalFormatSample;
use App\Enums\RoleCode;
use App\Models\InstitutionalFormat;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

final class InstitutionalFormatVisualPreviewController
{
    public function __invoke(
        InstitutionalFormat $format,
        RenderInstitutionalFormatSample $render,
    ): JsonResponse {
        $user = auth()->user();
        if (! $user instanceof User
            || $user->status !== 'active'
            || ! $user->hasVerifiedEmail()
            || ! $user->hasRole(RoleCode::Customer)
            || (int) $format->owner_id !== (int) $user->id) {
            throw new AccessDeniedHttpException();
        }

        $version = $format->versions()->reorder()->orderByDesc('number')->firstOrFail();
        $sample = $render->execute($version, $user);

        return response()->json([
            'ok' => true,
            'sample_id' => $sample->id,
            'pdf_url' => route('format-samples.download', [$sample, $sample->pdf_file_id]),
            'docx_url' => route('format-samples.download', [$sample, $sample->docx_file_id]),
        ]);
    }
}
