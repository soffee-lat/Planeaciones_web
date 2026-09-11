<?php

namespace App\Services\Documents;

use App\Exceptions\DocumentFormatException;
use App\Models\FormatVersion;
use App\Support\AI\CanonicalJson;

final class InstitutionalFormatMapping
{
    private const ALLOWED_ROOTS = ['planning','curricular_alignment','context','pedagogical_design','sessions','assessment_plan','resources','adaptation_notes'];

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
        $availableTokens = array_fill_keys(array_map('strval', is_array($analysis['placeholders'] ?? null) ? $analysis['placeholders'] : []), true);
        $anchors = $this->normalizeMap($mapping['anchors'] ?? [], $availableAnchors, 'FORMAT_MAPPING_ANCHOR_MISMATCH');
        $placeholders = $this->normalizeMap($mapping['placeholders'] ?? [], $availableTokens, 'FORMAT_MAPPING_PLACEHOLDER_MISMATCH');
        if ($anchors === [] && $placeholders === []) {
            throw new DocumentFormatException('FORMAT_MAPPING_INVALID');
        }

        return ['schema_version' => 2, 'anchors' => $anchors, 'placeholders' => $placeholders];
    }

    public function hash(array $mapping): string
    {
        return CanonicalJson::hash($mapping);
    }

    /** @return array{anchors:array<string,string>,placeholders:array<string,string>} */
    public function values(array $canonical, array $mapping): array
    {
        return $this->mappedValues($mapping, fn (string $path): string => $this->resolve($canonical, $path));
    }

    /** @return array{anchors:array<string,string>,placeholders:array<string,string>} */
    public function sampleValues(array $mapping): array
    {
        return $this->mappedValues($mapping, fn (string $path): string => match ($path) {
            'sessions' => 'MUESTRA · Sesión 1: inicio, desarrollo y cierre; Sesión 2: aplicación y evaluación.',
            'sessions.opening' => 'MUESTRA · Recuperación de saberes previos y presentación del reto.',
            'sessions.development' => 'MUESTRA · Actividades guiadas, colaborativas y de aplicación.',
            'sessions.closing' => 'MUESTRA · Socialización, reflexión y cierre.',
            'curricular_alignment.contents' => 'MUESTRA · Contenido curricular relacionado con el proyecto.',
            'curricular_alignment.pdas' => 'MUESTRA · Proceso de Desarrollo de Aprendizaje correspondiente.',
            default => 'MUESTRA · ' . str_replace(['_', '.'], [' ', ' / '], $path),
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
        return ['anchors' => $anchors, 'placeholders' => $placeholders];
    }

    /** @param mixed $raw @param array<string,bool> $available @return array<string,string> */
    private function normalizeMap(mixed $raw, array $available, string $mismatchCode): array
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
            $this->assertPath($path);
            $normalized[$key] = $path;
        }
        ksort($normalized, SORT_STRING);
        return $normalized;
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
            $this->assertPath($path);
            $normalized[$token] = $path;
        }
        ksort($normalized, SORT_STRING);
        if (array_keys($normalized) !== $expected) {
            throw new DocumentFormatException('FORMAT_MAPPING_PLACEHOLDER_MISMATCH');
        }
        return ['schema_version' => 1, 'placeholders' => $normalized];
    }

    private function assertPath(string $path): void
    {
        if (preg_match('/^[a-z][a-z0-9_]*(?:\.[a-z0-9_]+)*$/', $path) !== 1) {
            throw new DocumentFormatException('FORMAT_MAPPING_INVALID');
        }
        $root = explode('.', $path, 2)[0];
        if (! in_array($root, self::ALLOWED_ROOTS, true)) {
            throw new DocumentFormatException('FORMAT_MAPPING_PATH_NOT_ALLOWED:' . $path);
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
