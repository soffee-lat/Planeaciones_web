<?php

namespace App\Http\Controllers;

use App\Enums\RoleCode;
use App\Models\InstitutionalFormat;
use App\Models\User;
use App\Services\Documents\InstitutionalFormatFieldCatalog;
use Illuminate\Contracts\View\View;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

final class InstitutionalFormatDesignerController
{
    public function __invoke(InstitutionalFormat $format, InstitutionalFormatFieldCatalog $catalog): View
    {
        $user = auth()->user();
        $this->assertOwner($format, $user);

        $version = $format->versions()
            ->reorder()
            ->with(['sourceFile', 'samples'])
            ->orderByDesc('number')
            ->firstOrFail();

        $analysis = is_array(data_get($version->validation_report, 'analysis'))
            ? data_get($version->validation_report, 'analysis')
            : [];
        $mapping = is_array($version->mapping) ? $version->mapping : [];
        $fields = $catalog->options();

        foreach ((array) ($mapping['custom_fields'] ?? []) as $key => $definition) {
            if (is_array($definition)) {
                $fields['custom.' . $key] = 'Personalizado · ' . (string) ($definition['label'] ?? $key);
            }
        }

        return view('institutional-formats.designer', [
            'format' => $format,
            'version' => $version,
            'analysis' => $analysis,
            'zones' => array_values(array_filter((array) ($analysis['document_zones'] ?? []), 'is_array')),
            'anchors' => array_values(array_filter((array) ($analysis['anchors'] ?? []), 'is_array')),
            'mapping' => $mapping,
            'fields' => $fields,
            'sourceUrl' => route('institutional-formats.source', $format),
            'bindingUrl' => route('institutional-formats.visual-binding', $format),
            'backUrl' => '/app/institutional-formats/' . $format->id,
        ]);
    }

    private function assertOwner(InstitutionalFormat $format, mixed $user): void
    {
        if (! $user instanceof User
            || $user->status !== 'active'
            || ! $user->hasVerifiedEmail()
            || ! $user->hasRole(RoleCode::Customer)
            || (int) $format->owner_id !== (int) $user->id) {
            throw new AccessDeniedHttpException();
        }
    }
}
