<?php

namespace App\Services\Documents;

use App\Models\FormatVersion;
use Illuminate\Support\Str;

final class InstitutionalDynamicFieldResolver
{
    /**
     * Completa de forma determinista el mapping v2 con campos custom para
     * etiquetas que el catálogo estándar no reconoce. El resultado no muta la
     * versión publicada: se usa como contrato derivado tanto para muestras,
     * generación IA y render final.
     *
     * @param array<string,mixed> $mapping
     * @return array<string,mixed>
     */
    public function augment(FormatVersion $version, array $mapping): array
    {
        if (($mapping['schema_version'] ?? null) !== 2) {
            return $mapping;
        }

        $analysis = data_get($version->validation_report, 'analysis', []);
        if (! is_array($analysis)) {
            return $mapping;
        }

        $anchors = is_array($mapping['anchors'] ?? null) ? $mapping['anchors'] : [];
        $placeholders = is_array($mapping['placeholders'] ?? null) ? $mapping['placeholders'] : [];
        $customFields = is_array($mapping['custom_fields'] ?? null) ? $mapping['custom_fields'] : [];
        $ignored = array_fill_keys(array_map('strval', is_array($mapping['ignored_zones'] ?? null) ? $mapping['ignored_zones'] : []), true);

        foreach ((array) ($analysis['anchors'] ?? []) as $anchor) {
            if (! is_array($anchor)) {
                continue;
            }

            $id = trim((string) ($anchor['id'] ?? ''));
            $targetId = trim((string) ($anchor['target_id'] ?? $id));
            $label = trim((string) ($anchor['label'] ?? ''));
            $suggestedPath = trim((string) ($anchor['suggested_path'] ?? ''));

            if ($id === '' || $label === '' || isset($anchors[$id]) || $suggestedPath !== '') {
                continue;
            }
            if (isset($ignored[$id]) || ($targetId !== '' && isset($ignored[$targetId]))) {
                continue;
            }

            $key = $this->keyFor($label, $id);
            $customFields[$key] ??= [
                'label' => $label,
                'type' => 'long_text',
                'instruction' => sprintf(
                    'Redacta el contenido que corresponde al campo institucional “%s”. Usa el tema, currículo, contexto del grupo y secuencia didáctica. No inventes datos personales, firmas ni datos administrativos.',
                    $label,
                ),
            ];
            $anchors[$id] = 'custom.' . $key;
        }

        $knownPlaceholderSuggestions = is_array(data_get($analysis, 'suggested_mapping.placeholders'))
            ? data_get($analysis, 'suggested_mapping.placeholders')
            : [];

        foreach ((array) ($analysis['placeholders'] ?? []) as $tokenRaw) {
            $token = trim((string) $tokenRaw);
            if ($token === '' || isset($placeholders[$token]) || isset($knownPlaceholderSuggestions[$token])) {
                continue;
            }

            $label = Str::of($token)->lower()->replace(['_', '-', '.'], ' ')->headline()->toString();
            $key = $this->keyFor($label, 'token:' . $token);
            $customFields[$key] ??= [
                'label' => $label,
                'type' => 'long_text',
                'instruction' => sprintf(
                    'Genera el valor requerido por el marcador institucional “%s” a partir de la planeación. No inventes información personal o administrativa.',
                    $label,
                ),
            ];
            $placeholders[$token] = 'custom.' . $key;
        }

        ksort($anchors, SORT_STRING);
        ksort($placeholders, SORT_STRING);
        ksort($customFields, SORT_STRING);

        return [
            ...$mapping,
            'anchors' => $anchors,
            'placeholders' => $placeholders,
            'custom_fields' => $customFields,
        ];
    }

    private function keyFor(string $label, string $identity): string
    {
        $ascii = Str::ascii(mb_strtolower($label));
        $base = preg_replace('/[^a-z0-9]+/', '_', $ascii) ?? '';
        $base = trim($base, '_');
        if ($base === '' || preg_match('/^[a-z]/', $base) !== 1) {
            $base = 'campo_' . $base;
        }
        if (strlen($base) < 2) {
            $base = 'campo_' . $base;
        }

        $suffix = substr(hash('sha256', $identity), 0, 8);
        $maxBase = 64 - 1 - strlen($suffix);
        $base = substr($base, 0, max(2, $maxBase));
        $base = rtrim($base, '_');

        return $base . '_' . $suffix;
    }
}
