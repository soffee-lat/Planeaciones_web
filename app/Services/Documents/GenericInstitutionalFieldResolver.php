<?php

namespace App\Services\Documents;

use App\Models\FormatVersion;
use Illuminate\Support\Str;

/**
 * Completa el mapping de un formato sin asumir una estructura pedagógica
 * concreta. Cada zona desconocida puede convertirse en un campo propio del
 * formato. No conoce días, sesiones, grados, metodologías ni nombres de
 * apartados particulares: el DOCX y el contrato confirmado por el usuario son
 * la autoridad.
 */
final class GenericInstitutionalFieldResolver
{
    /**
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
        $fragments = is_array($mapping['fragments'] ?? null) ? $mapping['fragments'] : [];
        $customFields = is_array($mapping['custom_fields'] ?? null) ? $mapping['custom_fields'] : [];
        $ignored = array_fill_keys(
            array_map('strval', is_array($mapping['ignored_zones'] ?? null) ? $mapping['ignored_zones'] : []),
            true,
        );

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
            if (isset($ignored[$id]) || ($targetId !== '' && isset($ignored[$targetId])) || $this->manualOnly($label)) {
                continue;
            }

            $key = $this->customKey($label, $id);
            $customFields[$key] ??= [
                'label' => $label,
                'type' => 'long_text',
                'instruction' => $this->defaultInstruction($label),
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
            if ($this->manualOnly($label)) {
                continue;
            }

            $key = $this->customKey($label, 'token:' . $token);
            $customFields[$key] ??= [
                'label' => $label,
                'type' => 'long_text',
                'instruction' => $this->defaultInstruction($label),
            ];
            $placeholders[$token] = 'custom.' . $key;
        }

        ksort($anchors, SORT_STRING);
        ksort($placeholders, SORT_STRING);
        ksort($fragments, SORT_STRING);
        ksort($customFields, SORT_STRING);

        return [
            ...$mapping,
            'anchors' => $anchors,
            'placeholders' => $placeholders,
            'fragments' => $fragments,
            'custom_fields' => $customFields,
        ];
    }

    public function customKey(string $label, string $identity): string
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

    public function defaultInstruction(string $label): string
    {
        return sprintf(
            'Completa el apartado institucional “%s” de forma coherente con el tema, currículo, contexto del grupo y el resto de la planeación. Respeta la intención del formato y no inventes datos personales, administrativos, firmas ni sellos.',
            trim($label),
        );
    }

    private function manualOnly(string $label): bool
    {
        $text = mb_strtolower(Str::ascii($label));

        foreach ([
            'firma', 'sello', 'curp', 'telefono', 'direccion', 'nombre del alumno',
            'nombre de alumno', 'diagnostico', 'folio', 'autorizacion', 'vo.bo', 'vobo',
        ] as $term) {
            if (str_contains($text, $term)) {
                return true;
            }
        }

        return false;
    }
}
