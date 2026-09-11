<?php

namespace App\Services\Documents;

use App\Models\FormatVersion;
use Illuminate\Support\Str;

final class InstitutionalDynamicFieldResolver
{
    /**
     * Completa de forma determinista el mapping v2 con campos custom para
     * etiquetas que el catálogo estándar no reconoce. También reconoce
     * formatos institucionales que repiten un bloque diario por sesión y
     * enlaza cada bloque con la sesión correspondiente del plan canónico.
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
        $fragments = is_array($mapping['fragments'] ?? null) ? $mapping['fragments'] : [];
        $customFields = is_array($mapping['custom_fields'] ?? null) ? $mapping['custom_fields'] : [];
        $ignored = array_fill_keys(array_map('strval', is_array($mapping['ignored_zones'] ?? null) ? $mapping['ignored_zones'] : []), true);

        $sessionMapping = $this->augmentRepeatedSessionBlocks($analysis, $anchors, $fragments);
        $anchors = $sessionMapping['anchors'];
        $fragments = $sessionMapping['fragments'];
        $handledAnchorIds = $sessionMapping['handled_anchor_ids'];

        foreach ((array) ($analysis['anchors'] ?? []) as $anchor) {
            if (! is_array($anchor)) {
                continue;
            }

            $id = trim((string) ($anchor['id'] ?? ''));
            $targetId = trim((string) ($anchor['target_id'] ?? $id));
            $label = trim((string) ($anchor['label'] ?? ''));
            $suggestedPath = trim((string) ($anchor['suggested_path'] ?? ''));

            if ($id === '' || $label === '' || isset($anchors[$id]) || isset($handledAnchorIds[$id]) || $suggestedPath !== '' || $this->manualOnly($label)) {
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
            if ($this->manualOnly($label)) {
                continue;
            }

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

    /**
     * @param array<string,mixed> $analysis
     * @param array<string,string> $anchors
     * @param array<string,array<string,mixed>> $fragments
     * @return array{anchors:array<string,string>,fragments:array<string,array<string,mixed>>,handled_anchor_ids:array<string,bool>}
     */
    private function augmentRepeatedSessionBlocks(array $analysis, array $anchors, array $fragments): array
    {
        $zones = [];
        foreach ((array) ($analysis['document_zones'] ?? []) as $zone) {
            if (! is_array($zone) || ($zone['kind'] ?? null) !== 'cell') {
                continue;
            }
            $id = trim((string) ($zone['id'] ?? ''));
            if (preg_match('/^c:(\d+)$/', $id, $match) !== 1) {
                continue;
            }
            $zones[] = [
                'id' => $id,
                'index' => (int) $match[1],
                'text' => trim((string) ($zone['text_excerpt'] ?? '')),
            ];
        }
        usort($zones, static fn (array $a, array $b): int => $a['index'] <=> $b['index']);

        if ($zones === []) {
            return ['anchors' => $anchors, 'fragments' => $fragments, 'handled_anchor_ids' => []];
        }

        $analysisAnchors = [];
        foreach ((array) ($analysis['anchors'] ?? []) as $anchor) {
            if (is_array($anchor) && is_string($anchor['id'] ?? null)) {
                $analysisAnchors[$anchor['id']] = $anchor;
            }
        }

        $starts = [];
        foreach ($zones as $position => $zone) {
            $text = $this->normalize((string) $zone['text']);
            if (str_contains($text, 'formato flexible de planeacion didactica')) {
                $starts[] = $position;
            }
        }

        if ($starts === []) {
            return ['anchors' => $anchors, 'fragments' => $fragments, 'handled_anchor_ids' => []];
        }

        $handledAnchorIds = [];

        foreach ($starts as $sessionIndex => $startPosition) {
            $endPosition = $starts[$sessionIndex + 1] ?? count($zones);
            $block = array_slice($zones, $startPosition, $endPosition - $startPosition);
            if (! $this->looksLikeSessionBlock($block)) {
                continue;
            }

            foreach ($block as $position => $zone) {
                $id = (string) $zone['id'];
                $text = $this->normalize((string) $zone['text']);
                if ($text === '') {
                    continue;
                }

                if ($this->isStructuralSessionHeader($text)) {
                    unset($anchors[$id]);
                    $handledAnchorIds[$id] = true;
                    continue;
                }

                $prefix = 'sessions.' . $sessionIndex . '.';

                if (str_starts_with($text, 'grado:')) {
                    $this->mapLabelledZone($anchors, $analysisAnchors, $id, 'curricular_alignment.grade.name', $prefix . 'render.grade');
                    continue;
                }
                if (str_starts_with($text, 'grupo:')) {
                    $this->mapLabelledZone($anchors, $analysisAnchors, $id, 'context.group_name', $prefix . 'render.group');
                    continue;
                }
                if (preg_match('/^titulo(?: del proyecto)?\s*:/', $text) === 1) {
                    $this->mapLabelledZone($anchors, $analysisAnchors, $id, $prefix . 'title', $prefix . 'render.title');
                    continue;
                }
                if (str_starts_with($text, 'fecha:')) {
                    $this->mapLabelledZone($anchors, $analysisAnchors, $id, $prefix . 'date', $prefix . 'render.date');
                    continue;
                }
                if (str_starts_with($text, 'campo formativo:')) {
                    $this->mapLabelledZone($anchors, $analysisAnchors, $id, $prefix . 'fields', $prefix . 'render.fields');
                    continue;
                }
                if (str_starts_with($text, 'eje articulador:')) {
                    $this->mapLabelledZone($anchors, $analysisAnchors, $id, $prefix . 'axes', $prefix . 'render.axes');
                    continue;
                }
                if (str_starts_with($text, 'contenido:')) {
                    $this->mapLabelledZone($anchors, $analysisAnchors, $id, $prefix . 'contents', $prefix . 'render.contents');
                    continue;
                }
                if (preg_match('/^pda\s*:/', $text) === 1) {
                    $this->mapLabelledZone($anchors, $analysisAnchors, $id, $prefix . 'pdas', $prefix . 'render.pdas');
                    continue;
                }
                if (preg_match('/^fisicos?\s*[\.:]/', $text) === 1) {
                    $this->mapLabelledZone($anchors, $analysisAnchors, $id, $prefix . 'resources.physical', $prefix . 'render.resources_physical');
                    continue;
                }
                if (preg_match('/^digitales?\s*:/', $text) === 1) {
                    $this->mapLabelledZone($anchors, $analysisAnchors, $id, $prefix . 'resources.digital', $prefix . 'render.resources_digital');
                    continue;
                }
                if ($text === 'inicio' || $text === 'desarrollo' || $text === 'cierre') {
                    $path = $prefix . $text;
                    $anchor = $analysisAnchors[$id] ?? null;
                    $targetId = is_array($anchor) ? trim((string) ($anchor['target_id'] ?? '')) : '';
                    if ($targetId !== '' && $targetId !== $id) {
                        $anchors[$id] = $path;
                    } else {
                        $next = $block[$position + 1]['id'] ?? null;
                        if (is_string($next) && $next !== '') {
                            unset($anchors[$id]);
                            $anchors[$next] = $path;
                        }
                    }
                    continue;
                }
                if (str_starts_with($text, 'tarea')) {
                    $this->mapLabelledZone($anchors, $analysisAnchors, $id, $prefix . 'homework', $prefix . 'render.homework');
                    continue;
                }
                if (str_starts_with($text, 'propuesta de evaluacion')) {
                    $this->mapWholeZone($anchors, $fragments, $handledAnchorIds, $zone, $prefix . 'render.assessment', 'Propuesta de evaluación');
                    continue;
                }
                if (str_starts_with($text, 'evidencia')) {
                    $this->mapWholeZone($anchors, $fragments, $handledAnchorIds, $zone, $prefix . 'render.evidence', 'Evidencias');
                    continue;
                }
                if (str_starts_with($text, 'instrumento')) {
                    $this->mapWholeZone($anchors, $fragments, $handledAnchorIds, $zone, $prefix . 'render.instruments', 'Instrumento');
                }
            }
        }

        return ['anchors' => $anchors, 'fragments' => $fragments, 'handled_anchor_ids' => $handledAnchorIds];
    }

    /** @param array<string,string> $anchors @param array<string,array<string,mixed>> $fragments @param array<string,bool> $handledAnchorIds @param array{id:string,index:int,text:string} $zone */
    private function mapWholeZone(array &$anchors, array &$fragments, array &$handledAnchorIds, array $zone, string $path, string $label): void
    {
        $id = (string) $zone['id'];
        $sourceText = trim((string) $zone['text']);
        unset($anchors[$id]);
        $handledAnchorIds[$id] = true;

        if ($sourceText === '') {
            $anchors[$id] = $path;
            return;
        }

        $fragmentId = 'f_' . substr(hash('sha256', $id . '|' . $path . '|' . $sourceText), 0, 20);
        $fragments[$fragmentId] = [
            'zone_id' => $id,
            'start' => 0,
            'end' => mb_strlen($sourceText),
            'source_text' => $sourceText,
            'label_hint' => $label,
            'field_path' => $path,
        ];
    }

    /** @param list<array{id:string,index:int,text:string}> $block */
    private function looksLikeSessionBlock(array $block): bool
    {
        $joined = ' ' . implode(' ', array_map(fn (array $zone): string => $this->normalize($zone['text']), $block)) . ' ';
        $signals = 0;
        foreach ([' fecha:', ' campo formativo:', ' pda:', ' inicio ', ' desarrollo ', ' cierre '] as $needle) {
            if (str_contains($joined, $needle)) {
                $signals++;
            }
        }

        return $signals >= 4;
    }

    private function isStructuralSessionHeader(string $text): bool
    {
        return in_array($text, ['materiales', 'etapa', 'actividades'], true)
            || str_contains($text, 'secuencia didactica')
            || str_contains($text, 'seceuncia didactica');
    }

    /**
     * @param array<string,string> $anchors
     * @param array<string,array<string,mixed>> $analysisAnchors
     */
    private function mapLabelledZone(array &$anchors, array $analysisAnchors, string $id, string $path, string $fallbackPath): void
    {
        $anchor = $analysisAnchors[$id] ?? null;
        $targetId = is_array($anchor) ? trim((string) ($anchor['target_id'] ?? $id)) : '';
        $mode = is_array($anchor) ? trim((string) ($anchor['replacement_mode'] ?? '')) : '';

        if (is_array($anchor) && $targetId === $id && in_array($mode, ['replace_after_label', 'append_after_label', 'replace_target'], true)) {
            $anchors[$id] = $path;
            return;
        }

        unset($anchors[$id]);
        $anchors[$id] = $fallbackPath;
    }

    private function normalize(string $value): string
    {
        $value = mb_strtolower(Str::ascii($value));
        $value = preg_replace('/\s+/u', ' ', trim($value)) ?? trim($value);

        return trim($value);
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

    private function manualOnly(string $label): bool
    {
        $text = mb_strtolower(Str::ascii($label));

        foreach ([
            'firma', 'sello', 'curp', 'telefono', 'direccion', 'nombre del alumno',
            'nombre de alumno', 'diagnostico', 'folio', 'autorizacion',
        ] as $term) {
            if (str_contains($text, $term)) {
                return true;
            }
        }

        return false;
    }
}
