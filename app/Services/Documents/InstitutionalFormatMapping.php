<?php

namespace App\Services\Documents;

use App\Exceptions\DocumentFormatException;
use App\Models\FormatVersion;
use App\Support\AI\CanonicalJson;

final class InstitutionalFormatMapping
{
    /** @var list<string> */
    private const ALLOWED_ROOTS = [
        'planning',
        'curricular_alignment',
        'context',
        'pedagogical_design',
        'sessions',
        'assessment_plan',
        'resources',
        'adaptation_notes',
    ];

    /** @param array<string,mixed> $mapping @return array{schema_version:int,placeholders:array<string,string>} */
    public function validate(FormatVersion $version, array $mapping): array
    {
        if (($mapping['schema_version'] ?? null) !== 1
            || ! isset($mapping['placeholders'])
            || ! is_array($mapping['placeholders'])
            || $mapping['placeholders'] === []) {
            throw new DocumentFormatException('FORMAT_MAPPING_INVALID');
        }

        $analysis = $version->validation_report['analysis'] ?? null;
        $expected = is_array($analysis) && is_array($analysis['placeholders'] ?? null)
            ? array_values(array_map('strval', $analysis['placeholders']))
            : [];
        sort($expected, SORT_STRING);
        if ($expected === []) {
            throw new DocumentFormatException('FORMAT_ANALYSIS_REQUIRED');
        }

        $normalized = [];
        foreach ($mapping['placeholders'] as $token => $path) {
            $token = trim((string) $token);
            $path = trim((string) $path);
            if (preg_match('/^[A-Z][A-Z0-9_.-]{1,63}$/', $token) !== 1
                || preg_match('/^[a-z][a-z0-9_]*(?:\.[a-z0-9_]+)*$/', $path) !== 1) {
                throw new DocumentFormatException('FORMAT_MAPPING_INVALID');
            }
            $root = explode('.', $path, 2)[0];
            if (! in_array($root, self::ALLOWED_ROOTS, true)) {
                throw new DocumentFormatException('FORMAT_MAPPING_PATH_NOT_ALLOWED:' . $path);
            }
            $normalized[$token] = $path;
        }
        ksort($normalized, SORT_STRING);
        if (array_keys($normalized) !== $expected) {
            throw new DocumentFormatException('FORMAT_MAPPING_PLACEHOLDER_MISMATCH');
        }

        return ['schema_version' => 1, 'placeholders' => $normalized];
    }

    /** @param array<string,mixed> $mapping */
    public function hash(array $mapping): string
    {
        return CanonicalJson::hash($mapping);
    }

    /** @param array<string,mixed> $canonical @param array{schema_version:int,placeholders:array<string,string>} $mapping @return array<string,string> */
    public function replacements(array $canonical, array $mapping): array
    {
        $values = [];
        foreach ($mapping['placeholders'] as $token => $path) {
            $values[$token] = $this->stringify(data_get($canonical, $path));
        }

        return $values;
    }

    /** @param array{schema_version:int,placeholders:array<string,string>} $mapping @return array<string,string> */
    public function sampleReplacements(array $mapping): array
    {
        $values = [];
        foreach ($mapping['placeholders'] as $token => $path) {
            $values[$token] = match ($path) {
                'sessions' => 'MUESTRA · Sesión 1: inicio, desarrollo y cierre; Sesión 2: aplicación y evaluación.',
                'curricular_alignment.contents' => 'MUESTRA · Contenido curricular 1; Contenido curricular 2.',
                'curricular_alignment.pdas' => 'MUESTRA · PDA 1; PDA 2.',
                default => 'MUESTRA · ' . str_replace(['_', '.'], [' ', ' / '], $path),
            };
        }

        return $values;
    }

    private function stringify(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? 'Sí' : 'No';
        }
        if (is_scalar($value)) {
            return trim((string) $value);
        }
        if (! is_array($value)) {
            return '';
        }

        $parts = [];
        foreach ($value as $key => $item) {
            $text = $this->stringify($item);
            if ($text === '') {
                continue;
            }
            $parts[] = is_string($key) ? str_replace('_', ' ', $key) . ': ' . $text : $text;
        }

        return implode('; ', $parts);
    }
}
