<?php

namespace App\Services\Documents;

use App\Exceptions\DocumentFormatException;
use App\Models\FormatVersion;
use App\Support\AI\CanonicalJson;
use Carbon\CarbonImmutable;

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

        return $this->mappedValues($mapping, fn (string $path): string => $this->sampleValue($path, $customFields));
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
        if (preg_match('/^sessions\.(\d+)\.(.+)$/', $path, $match) === 1) {
            return $this->resolveIndexedSession($canonical, (int) $match[1], (string) $match[2]);
        }
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

    /** @param array<string,mixed> $canonical */
    private function resolveIndexedSession(array $canonical, int $index, string $field): string
    {
        $session = $canonical['sessions'][$index] ?? null;
        if (! is_array($session)) {
            return '';
        }

        if (str_starts_with($field, 'render.')) {
            $renderField = substr($field, strlen('render.'));
            $value = $this->resolveIndexedSession($canonical, $index, match ($renderField) {
                'grade' => 'grade',
                'group' => 'group',
                'title' => 'title',
                'date' => 'date',
                'fields' => 'fields',
                'axes' => 'axes',
                'contents' => 'contents',
                'pdas' => 'pdas',
                'resources_physical' => 'resources.physical',
                'resources_digital' => 'resources.digital',
                'homework' => 'homework',
                'assessment' => 'assessment',
                'evidence' => 'evidence',
                'instruments' => 'instruments',
                default => $renderField,
            });
            if ($value === '') {
                return '';
            }

            $label = match ($renderField) {
                'grade' => 'GRADO',
                'group' => 'GRUPO',
                'title' => 'TITULO DEL PROYECTO',
                'date' => 'FECHA',
                'fields' => 'CAMPO FORMATIVO',
                'axes' => 'EJE ARTICULADOR',
                'contents' => 'CONTENIDO',
                'pdas' => 'PDA',
                'resources_physical' => 'FÍSICOS',
                'resources_digital' => 'DIGITALES',
                'homework' => 'TAREA',
                'assessment' => 'PROPUESTA DE EVALUACIÓN',
                'evidence' => 'EVIDENCIAS',
                'instruments' => 'INSTRUMENTO',
                default => mb_strtoupper(str_replace('_', ' ', $renderField)),
            };

            return $label . ': ' . $value;
        }

        return match ($field) {
            'grade' => $this->stringify(data_get($canonical, 'curricular_alignment.grade.name')),
            'group' => $this->stringify(data_get($canonical, 'context.group_name')),
            'title' => trim((string) ($session['title'] ?? '')),
            'date' => $this->formatSessionDate((string) ($session['date'] ?? '')),
            'fields' => $this->expandCodes($canonical, (array) ($session['field_codes'] ?? []), 'curricular_alignment.fields', 'name'),
            'axes' => $this->expandCodes($canonical, (array) ($session['axis_codes'] ?? []), 'curricular_alignment.articulating_axes', 'name'),
            'contents' => $this->expandCodes($canonical, (array) ($session['content_codes'] ?? []), 'curricular_alignment.contents', 'full_text'),
            'pdas' => $this->expandCodes($canonical, (array) ($session['pda_codes'] ?? []), 'curricular_alignment.pdas', 'full_text'),
            'resources.physical' => $this->sessionResources($canonical, $session, false),
            'resources.digital' => $this->sessionResources($canonical, $session, true),
            'opening', 'development', 'closing' => $this->sessionMoment($session, $field),
            'homework' => trim((string) ($session['homework_or_extension'] ?? '')),
            'assessment' => $this->sessionAssessment($session),
            'evidence' => $this->stringify(data_get($session, 'formative_assessment.evidence')),
            'instruments' => $this->sessionInstruments($canonical, $session),
            default => $this->stringify(data_get($session, $field)),
        };
    }

    /** @param array<string,mixed> $canonical @param list<mixed> $codes */
    private function expandCodes(array $canonical, array $codes, string $collectionPath, string $valueKey): string
    {
        $wanted = array_fill_keys(array_map('strval', $codes), true);
        $values = [];
        foreach ((array) data_get($canonical, $collectionPath, []) as $row) {
            if (! is_array($row)) {
                continue;
            }
            $code = (string) ($row['code'] ?? '');
            if ($code === '' || ! isset($wanted[$code])) {
                continue;
            }
            $value = trim((string) ($row[$valueKey] ?? $row['name'] ?? $row['title'] ?? $code));
            if ($value !== '') {
                $values[] = $value;
            }
        }

        return implode('; ', array_values(array_unique($values)));
    }

    /** @param array<string,mixed> $session */
    private function sessionMoment(array $session, string $type): string
    {
        $items = [];
        foreach ((array) ($session['moments'] ?? []) as $moment) {
            if (! is_array($moment) || ($moment['type'] ?? null) !== $type) {
                continue;
            }
            foreach ((array) ($moment['activities'] ?? []) as $activity) {
                if (! is_array($activity)) {
                    continue;
                }
                $instruction = trim((string) ($activity['instruction'] ?? ''));
                if ($instruction !== '') {
                    $items[] = $instruction;
                }
            }
        }

        return implode("\n", $items);
    }

    /** @param array<string,mixed> $canonical @param array<string,mixed> $session */
    private function sessionResources(array $canonical, array $session, bool $digital): string
    {
        $materials = [];
        foreach ((array) ($session['moments'] ?? []) as $moment) {
            if (! is_array($moment)) continue;
            foreach ((array) ($moment['activities'] ?? []) as $activity) {
                if (! is_array($activity)) continue;
                foreach ((array) ($activity['materials'] ?? []) as $material) {
                    $value = trim((string) $material);
                    if ($value !== '') {
                        $materials[] = $value;
                    }
                }
            }
        }

        $resourcePath = $digital ? 'resources.digital_resources' : 'resources.physical_materials';
        foreach ((array) data_get($canonical, $resourcePath, []) as $resource) {
            $value = trim((string) $resource);
            if ($value !== '') {
                $materials[] = $value;
            }
        }

        $materials = array_values(array_unique($materials));
        if ($digital) {
            $materials = array_values(array_filter($materials, static function (string $value): bool {
                $text = mb_strtolower($value);
                return str_contains($text, 'http') || str_contains($text, 'www.') || str_contains($text, 'youtube')
                    || str_contains($text, 'video') || str_contains($text, 'digital') || str_contains($text, 'proyector')
                    || str_contains($text, 'computadora') || str_contains($text, 'tablet');
            }));
        }

        return implode(', ', $materials);
    }

    /** @param array<string,mixed> $session */
    private function sessionAssessment(array $session): string
    {
        $parts = [];
        $criteria = $this->stringify(data_get($session, 'formative_assessment.criteria'));
        $feedback = trim((string) data_get($session, 'formative_assessment.feedback_strategy', ''));
        if ($criteria !== '') {
            $parts[] = 'Criterios: ' . $criteria;
        }
        if ($feedback !== '') {
            $parts[] = 'Retroalimentación: ' . $feedback;
        }

        return implode('; ', $parts);
    }

    /** @param array<string,mixed> $canonical @param array<string,mixed> $session */
    private function sessionInstruments(array $canonical, array $session): string
    {
        $wanted = array_fill_keys(array_map('strval', (array) data_get($session, 'formative_assessment.instrument_ids', [])), true);
        $values = [];
        foreach ((array) data_get($canonical, 'assessment_plan.instruments', []) as $instrument) {
            if (! is_array($instrument)) continue;
            $id = (string) ($instrument['id'] ?? '');
            if ($id === '' || ! isset($wanted[$id])) continue;
            $name = trim((string) ($instrument['name'] ?? $instrument['type'] ?? $id));
            if ($name !== '') {
                $values[] = $name;
            }
        }

        return implode('; ', array_values(array_unique($values)));
    }

    private function formatSessionDate(string $date): string
    {
        if ($date === '') {
            return '';
        }

        try {
            return mb_strtoupper(CarbonImmutable::parse($date)->locale('es')->translatedFormat('l d \\d\\e F \\d\\e\\l Y'));
        } catch (\Throwable) {
            return $date;
        }
    }

    /** @param array<string,array<string,string>> $customFields */
    private function sampleValue(string $path, array $customFields): string
    {
        if (str_starts_with($path, 'custom.')) {
            $key = substr($path, strlen('custom.'));
            $label = (string) data_get($customFields, $key . '.label', str_replace('_', ' ', $key));
            return 'MUESTRA · ' . $label;
        }

        if (preg_match('/^sessions\.(\d+)\.(.+)$/', $path, $match) === 1) {
            $sessionNumber = (int) $match[1] + 1;
            $field = (string) $match[2];
            if (str_starts_with($field, 'render.')) {
                $renderField = substr($field, strlen('render.'));
                $label = match ($renderField) {
                    'grade' => 'GRADO', 'group' => 'GRUPO', 'title' => 'TITULO DEL PROYECTO', 'date' => 'FECHA',
                    'fields' => 'CAMPO FORMATIVO', 'axes' => 'EJE ARTICULADOR', 'contents' => 'CONTENIDO', 'pdas' => 'PDA',
                    'resources_physical' => 'FÍSICOS', 'resources_digital' => 'DIGITALES', 'homework' => 'TAREA',
                    'assessment' => 'PROPUESTA DE EVALUACIÓN', 'evidence' => 'EVIDENCIAS', 'instruments' => 'INSTRUMENTO',
                    default => mb_strtoupper(str_replace('_', ' ', $renderField)),
                };
                return $label . ': MUESTRA · Sesión ' . $sessionNumber;
            }

            return match ($field) {
                'title' => 'MUESTRA · Proyecto de la sesión ' . $sessionNumber,
                'date' => 'MUESTRA · Fecha de la sesión ' . $sessionNumber,
                'fields' => 'MUESTRA · Campo formativo de la sesión ' . $sessionNumber,
                'axes' => 'MUESTRA · Eje articulador de la sesión ' . $sessionNumber,
                'contents' => 'MUESTRA · Contenido de la sesión ' . $sessionNumber,
                'pdas' => 'MUESTRA · PDA de la sesión ' . $sessionNumber,
                'opening' => 'MUESTRA · Inicio de la sesión ' . $sessionNumber,
                'development' => 'MUESTRA · Desarrollo de la sesión ' . $sessionNumber,
                'closing' => 'MUESTRA · Cierre de la sesión ' . $sessionNumber,
                default => 'MUESTRA · Sesión ' . $sessionNumber . ' · ' . str_replace(['_', '.'], [' ', ' / '], $field),
            };
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
    }

    private function stringify(mixed $value): string
    {
        if ($value === null) return '';
        if (is_bool($value)) return $value ? 'Sí' : 'No';
        if (is_scalar($value)) return trim((string) $value);
        if (! is_array($value) || $value === []) return '';

        // Conserva el comportamiento compacto de los objetos canónicos que ya
        // tienen un valor representativo conocido.
        if (! array_is_list($value)) {
            foreach (['full_text', 'title', 'name', 'instruction', 'code'] as $preferred) {
                if (array_key_exists($preferred, $value) && is_scalar($value[$preferred])) {
                    $text = trim((string) $value[$preferred]);
                    if ($text !== '') {
                        return $text;
                    }
                }
            }

            // Para tablas/bloques propios del formato no conocemos sus claves.
            // Las serializamos de forma legible sin imponer un esquema escolar.
            $parts = [];
            foreach ($value as $key => $item) {
                $text = $this->stringify($item);
                if ($text === '') continue;
                $label = trim(str_replace('_', ' ', (string) $key));
                $parts[] = ($label !== '' ? $label . ': ' : '') . $text;
            }
            return implode("\n", $parts);
        }

        $parts = [];
        foreach ($value as $item) {
            $text = $this->stringify($item);
            if ($text !== '') {
                $parts[] = $text;
            }
        }

        return implode("\n", $parts);
    }
}
