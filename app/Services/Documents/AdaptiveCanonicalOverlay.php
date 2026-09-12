<?php

namespace App\Services\Documents;

use App\Services\AI\CanonicalPlanValidator;

final class AdaptiveCanonicalOverlay
{
    /** @param array<string,mixed> $canonical @return array<string,mixed> */
    public function forRendering(array $canonical): array
    {
        if (($canonical['schema_version'] ?? null) !== CanonicalPlanValidator::ADAPTIVE_SCHEMA_VERSION) {
            return $canonical;
        }

        $fields = is_array($canonical['template_fields'] ?? null) ? $canonical['template_fields'] : [];
        foreach ($fields as $path => $value) {
            $path = trim((string) $path);
            if ($path === '' || ! $this->safePath($path)) {
                continue;
            }
            data_set($canonical, $path, $value);
        }

        return $canonical;
    }

    private function safePath(string $path): bool
    {
        if (preg_match('/^[a-z][a-z0-9_]*(?:\.[a-z0-9_]+)*$/', $path) !== 1) {
            return false;
        }

        $root = explode('.', $path, 2)[0];

        return in_array($root, [
            'planning', 'pedagogical_design', 'sessions', 'assessment_plan',
            'resources', 'adaptation_notes',
        ], true);
    }
}
