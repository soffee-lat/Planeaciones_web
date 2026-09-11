<?php

namespace App\Services\Documents;

use App\Exceptions\DocumentFormatException;
use App\Models\FormatVersion;
use App\Support\AI\CanonicalJson;

final class InstitutionalFormatMapping
{
    private const ALLOWED_ROOTS = ['planning','curricular_alignment','context','pedagogical_design','sessions','assessment_plan','resources','adaptation_notes','custom'];

    /** @param array<string,mixed> $mapping @return array<string,mixed> */
    public function validate(FormatVersion $version, array $mapping): array
    {
        $analysis = $version->validation_report['analysis'] ?? null;
        if (! is_array($analysis)) {
            throw new DocumentFormatException('FORMAT_ANALYSIS_REQUIRED');
        }

        if (($mapping['schema_version'] ?? null) === 1) {
            return $this->validateLegacy($analysis, $mapping);
        }
        if (($mapping['schema_version'] ?? null) !== 2) {
            throw new DocumentFormatException('FORMAT_MAPPING_INVALID');
        }

        $availableAnchors = [];
        foreach (($analysis['anchors'] ?? []) as $anchor) {
            if (is_array($anchor) && is_string($anchor['id'] ?? null)) {
                $availableAnchors[$anchor['id']] = true;
            }
        }
        foreach (($analysis['document_zones'] ?? []) as $zone) {
            if (is_array($zone) && is_string($zone['id'] ?? null)) {
                $availableAnchors[$zone['id']] = true;
            }
        }

        $customFields = $this->normalizeCustomFields($mapping['custom_fields'] ?? []);
        $availableTokens = array_fill_keys(array_map('strval', is_array($analysis['placeholders'] ?? null) ? $analysis['placeholders'] : []), true);
        $anchors = $this->normalizeMap($mapping['anchors'] ?? [], $availableAnchors, 'FORMAT_MAPPING_ANCHOR_MISMATCH', $customFields);
        $placeholders = $this->normalizeMap($mapping['placeholders'] ?? [], $availableTokens, 'FORMAT_MAPPING_PLACEHOLDER_MISMATCH', $customFields);
        $fragments = $this->normalizeFragments($mapping['fragments'] ?? [], $availableAnchors, $customFields);
        $ignoredZones = $this->normalizeIgnoredZones($mapping['ignored_zones'] ?? [], $availableAnchors);

        if ($anchors === [] && $placeholders === [] && $fragments === [] && $ignoredZones === [] && $customFields === []) {
            throw new DocumentFormatException('FORMAT_MAPPING_INVALID');
        }

        return [
            'schema_version' => 2,
            'anchors' => $anchors,
            'placeholders' => $placeholders,
            'fragments' => $fragments,
            'custom_fields' => $customFields,
            'ignored_zones' => $ignoredZones,
        ];
    }

    public function hash(array $mapping): string
    {
        return CanonicalJson::hash($mapping);
    }

    /** @return array{anchors:array<string,string>,placeholders:array<string,string>,fragments:array<string,array<string,mixed>>} */
    public function values(array $canonical, array $mapping): array
    {
        return $this->mappedValues($mapping, fn (string $path): string => $this->resolve($canonical, $path));
    }

    /** @return array{anchors:array<string,string>,placeholders:array<string,string>,fragments:array<string,array<string,mixed>>} */
    public function sampleValues(array $mapping): array
    {
        $customFields = is_array($mapping['custom_fields'] ?? null) ? $mapping['custom_fields'] : [];

        return $this->mappedValues($mapping, function (string $path) use ($customFields): string {
            if (str_starts_with($path, 'custom.')) {
                $key = substr($path, strlen('custom.'));
                $label = (string) data_get($customFields, $key . '.label', str_replace('_', ' ', $key));
                return 'MUESTRA · ' . $label;
            }

            return match ($path) {
                'sessions' => 'MUESTRA · Sesión 1: inicio, desarrollo y cierre; Sesión 2: aplicación y evaluación.',
                'sessions.opening' => 'MUESTRA · Recuperación de saberes previos y presentación del reto.',
                'sessions.development' => 'MUESTRA · Actividades guiadas, colaborativas y de aplicación.',
                'sessions.closing' => 'MUESTRA · Socialización, reflexión y cierre.',
                'curricular_alignment.contents' => 'MUESTRA · Contenido curricular relacionado con el proyecto.',
                'curricular_alignment.pdas' => 'MUESTRA · Proceso de Desarrollo de Aprendizaje correspondiente.',
                default => 'MUESTRA · ' . str_replace(['_', '.'], [' ', ' / '], $path),
            };
        });
    }

    /** @param array<string,mixed> $mapping @param callable(string):string $resolver */
    private function mappedValues(array $mapping, callable $resolver): array
    {
        $anchors = [];
        foreach (($mapping['anchors'] ?? []) as $id => $path) {
            $anchors[(string) $id] = $resolver((string) $path);
        }
        $placeholders = [];
        foreach (($mapping['placeholders'] ?? []) as $token => $path) {
            $placeholders[(string) $token] = $resolver((string) $path);
        }
        $fragments = [];
        foreach (($mapping['fragments'] ?? []) as $id => $definition) {
            if (! is_array($definition)) {
                continue;
            }
            $path = (string) ($definition['field_path'] ?? '');
            if ($path === '') {
                continue;
            }
            $fragments[(string) $id] = [
                ...$definition,
                'value' => $resolver($path),
            ];
        }
        return ['anchors' => $anchors, 'placeholders' => $placeholders, 'fragments' => $fragments];
    }

    /** @param mixed $raw @param array<string,bool> $available @param array<string,array<string,string>> $customFields @return array<string,string> */
    private function normalizeMap(mixed $raw, array $available, string $mismatchCode, array $customFields): array
    {
        if ($raw === null) {
            return [];
        }
        if (! is_array($raw)) {
            throw new DocumentFormatException('FORMAT_MAPPING_INVALID');
        }
        $normalized = [];
        foreach ($raw as $key => $path) {
            $key = trim((string) $key);
            $path = trim((string) $path);
            if ($path === '') {
                continue;
            }
            if (! isset($available[$key])) {
                throw new DocumentFormatException($mismatchCode);
            }
            $this->assertPath($path, $customFields);
            $normalized[$key] = $path;
        }
        ksort($normalized, SORT_STRING);
        return $normalized;
    }

    /** @param mixed $raw @param array<string,bool> $available @param array<string,array<string,string>> $customFields @return array<string,array<string,mixed>> */
    private function normalizeFragments(mixed $raw, array $available, array $customFields): array
    {
        if ($raw === null || $raw === []) {
            return [];
        }
        if (! is_array($raw)) {
            throw new DocumentFormatException('FORMAT_MAPPING_FRAGMENT_INVALID');
        }

        $normalized = [];
        $rangesByZone = [];
        foreach ($raw as $id => $definition) {
            $id = trim((string) $id);
            if (preg_match('/^f_[a-f0-9]{12,64}$/', $id) !== 1 || ! is_array($definition)) {
                throw new DocumentFormatException('FORMAT_MAPPING_FRAGMENT_INVALID');
            }

            $zoneId = trim((string) ($definition['zone_id'] ?? ''));
            $start = filter_var($definition['start'] ?? null, FILTER_VALIDATE_INT);
            $end = filter_var($definition['end'] ?? null, FILTER_VALIDATE_INT);
            $sourceText = trim((string) ($definition['source_text'] ?? ''));
            $labelHint = trim((string) ($definition['label_hint'] ?? ''));
            $path = trim((string) ($definition['field_path'] ?? ''));

            if (! isset($available[$zoneId]) || $start === false || $end === false || $start < 0 || $end <= $start || $end > 50000
                || $sourceText === '' || mb_strlen($sourceText) > 1000 || mb_strlen($labelHint) > 160 || $path === '') {
                throw new DocumentFormatException('FORMAT_MAPPING_FRAGMENT_INVALID');
            }
            $this->assertPath($path, $customFields);

            foreach ($rangesByZone[$zoneId] ?? [] as [$existingStart, $existingEnd]) {
                if ($start < $existingEnd && $end > $existingStart) {
                    throw new DocumentFormatException('FORMAT_MAPPING_FRAGMENT_OVERLAP');
                }
            }
            $rangesByZone[$zoneId][] = [$start, $end];

            $normalized[$id] = [
                'zone_id' => $zoneId,
                'start' => $start,
                'end' => $end,
                'source_text' => $sourceText,
                'label_hint' => $labelHint,
                'field_path' => $path,
            ];
        }
        ksort($normalized, SORT_STRING);
        return $normalized;
    }

    /** @param mixed $raw @return array<string,array{label:string,type:string,instruction:string}> */
    private function normalizeCustomFields(mixed $raw): array
    {
        if ($raw === null || $raw === []) {
            return [];
        }
        if (! is_array($raw)) {
            throw new DocumentFormatException('FORMAT_MAPPING_CUSTOM_FIELDS_INVALID');
        }

        $normalized = [];
        foreach ($raw as $key => $definition) {
            $key = trim((string) $key);
            if (preg_match('/^[a-z][a-z0-9_]{1,63}$/', $key) !== 1 || ! is_array($definition)) {
                throw new DocumentFormatException('FORMAT_MAPPING_CUSTOM_FIELDS_INVALID');
            }
            $label = trim((string) ($definition['label'] ?? ''));
            $type = trim((string) ($definition['type'] ?? 'long_text'));
            $instruction = trim((string) ($definition['instruction'] ?? ''));
            if ($label === '' || mb_strlen($label) > 120 || ! in_array($type, ['text', 'long_text', 'date', 'list', 'table', 'repeating_block'], true) || mb_strlen($instruction) > 1000) {
                throw new DocumentFormatException('FORMAT_MAPPING_CUSTOM_FIELDS_INVALID');
            }
            $normalized[$key] = ['label' => $label, 'type' => $type, 'instruction' => $instruction];
        }
        ksort($normalized, SORT_STRING);
        return $normalized;
    }

    /** @param mixed $raw @param array<string,bool> $available @return list<string> */
    private function normalizeIgnoredZones(mixed $raw, array $available): array
    {
        if ($raw === null || $raw === []) {
            return [];
        }
        if (! is_array($raw)) {
            throw new DocumentFormatException('FORMAT_MAPPING_INVALID');
        }
        $zones = [];
        foreach ($raw as $zone) {
            $zone = trim((string) $zone);
            if ($zone === '' || ! isset($available[$zone])) {
                throw new DocumentFormatException('FORMAT_MAPPING_ANCHOR_MISMATCH');
            }
            $zones[$zone] = true;
        }
        $values = array_keys($zones);
        sort($values, SORT_STRING);
        return $values;
    }

    /** @param array<string,mixed> $analysis @param array<string,mixed> $mapping */
    private function validateLegacy(array $analysis, array $mapping): array
    {
        if (! is_array($mapping['placeholders'] ?? null) || $mapping['placeholders'] === []) {
            throw new DocumentFormatException('FORMAT_MAPPING_INVALID');
        }
        $expected = array_values(array_map('strval', is_array($analysis['placeholders'] ?? null) ? $analysis['placeholders'] : []));
        sort($expected, SORT_STRING);
        $normalized = [];
        foreach ($mapping['placeholders'] as $token => $path) {
            $token = trim((string) $token);
            $path = trim((string) $path);
            $this->assertPath($path, []);
            $normalized[$token] = $path;
        }
        ksort($normalized, SORT_STRING);
        if (array_keys($normalized) !== $expected) {
            throw new DocumentFormatException('FORMAT_MAPPING_PLACEHOLDER_MISMATCH');
        }
        return ['schema_version' => 1, 'placeholders' => $normalized];
    }

    /** @param array<string,array<string,string>> $customFields */
    private function assertPath(string $path, array $customFields): void
    {
        if (preg_match('/^[a-z][a-z0-9_]*(?:\.[a-z0-9_]+)*$/', $path) !== 1) {
            throw new DocumentFormatException('FORMAT_MAPPING_INVALID');
        }
        $root = explode('.', $path, 2)[0];
        if (! in_array($root, self::ALLOWED_ROOTS, true)) {
            throw new DocumentFormatException('FORMAT_MAPPING_PATH_NOT_ALLOWED:' . $path);
        }
        if ($root === 'custom') {
            $key = substr($path, strlen('custom.'));
            if ($key === '' || ! isset($customFields[$key])) {
                throw new DocumentFormatException('FORMAT_MAPPING_CUSTOM_FIELD_NOT_DEFINED:' . $path);
            }
        }
    }

    /** @param array<string,mixed> $canonical */
    private function resolve(array $canonical, string $path): string
    {
        if (str_starts_with($path, 'sessions.')) {
            $type = substr($path, strlen('sessions.'));
            $items = [];
            foreach (($canonical['sessions'] ?? []) as $session) {
                if (! is_array($session)) continue;
                foreach (($session['moments'] ?? []) as $moment) {
                    if (! is_array($moment) || ($moment['type'] ?? null) !== $type) continue;
                    foreach (($moment['activities'] ?? []) as $activity) {
                        if (is_array($activity) && trim((string) ($activity['instruction'] ?? '')) !== '') {
                            $items[] = trim((string) $activity['instruction']);
                        }
                    }
                }
            }
            return implode('; ', $items);
        }
        return $this->stringify(data_get($canonical, $path));
    }

    private function stringify(mixed $value): string
    {
        if ($value === null) return '';
        if (is_bool($value)) return $value ? 'Sí' : 'No';
        if (is_scalar($value)) return trim((string) $value);
        if (! is_array($value)) return '';
        $parts = [];
        foreach ($value as $key => $item) {
            if (is_array($item) && ! array_is_list($item)) {
                $text = trim((string) ($item['full_text'] ?? $item['title'] ?? $item['name'] ?? $item['code'] ?? ''));
            } else {
                $text = $this->stringify($item);
            }
            if ($text === '') continue;
            $parts[] = is_string($key) && ! is_array($item) ? str_replace('_', ' ', $key) . ': ' . $text : $text;
        }
        return implode('; ', $parts);
    }
}
