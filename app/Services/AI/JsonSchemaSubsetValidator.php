<?php

namespace App\Services\AI;

use App\Exceptions\AiContractException;
use DateTimeImmutable;

/**
 * Validador deliberadamente pequeño para el subconjunto JSON Schema 2020-12
 * usado por los contratos v1 del proyecto. No pretende implementar JSON Schema
 * completo ni sustituir una librería futura especializada.
 */
class JsonSchemaSubsetValidator
{
    /** @param array<string,mixed> $schema */
    public function validate(mixed $value, array $schema, string $path = '$', ?array $rootSchema = null): void
    {
        $rootSchema ??= $schema;

        if (isset($schema['$ref'])) {
            $resolved = $this->resolveRef((string) $schema['$ref'], $rootSchema);
            $this->validate($value, $resolved, $path, $rootSchema);
            return;
        }

        if (array_key_exists('const', $schema) && $value !== $schema['const']) {
            throw new AiContractException('AI_SCHEMA_CONST_MISMATCH', $path);
        }

        if (isset($schema['enum']) && ! in_array($value, $schema['enum'], true)) {
            throw new AiContractException('AI_SCHEMA_ENUM_MISMATCH', $path);
        }

        if (array_key_exists('type', $schema)) {
            $types = is_array($schema['type']) ? $schema['type'] : [$schema['type']];
            if (! $this->matchesAnyType($value, $types)) {
                throw new AiContractException('AI_SCHEMA_TYPE_MISMATCH', $path, 'expected=' . implode('|', $types));
            }
        }

        if (is_string($value)) {
            $length = function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
            if (isset($schema['minLength']) && $length < (int) $schema['minLength']) {
                throw new AiContractException('AI_SCHEMA_MIN_LENGTH', $path);
            }
            if (isset($schema['maxLength']) && $length > (int) $schema['maxLength']) {
                throw new AiContractException('AI_SCHEMA_MAX_LENGTH', $path);
            }
            if (isset($schema['pattern']) && preg_match('/' . str_replace('/', '\\/', (string) $schema['pattern']) . '/u', $value) !== 1) {
                throw new AiContractException('AI_SCHEMA_PATTERN_MISMATCH', $path);
            }
            if (($schema['format'] ?? null) === 'date' && ! $this->isValidDate($value)) {
                throw new AiContractException('AI_SCHEMA_INVALID_DATE', $path);
            }
        }

        if (is_int($value) || is_float($value)) {
            if (isset($schema['minimum']) && $value < $schema['minimum']) {
                throw new AiContractException('AI_SCHEMA_MINIMUM', $path);
            }
            if (isset($schema['maximum']) && $value > $schema['maximum']) {
                throw new AiContractException('AI_SCHEMA_MAXIMUM', $path);
            }
        }

        if (is_array($value) && array_is_list($value)) {
            $count = count($value);
            if (isset($schema['minItems']) && $count < (int) $schema['minItems']) {
                throw new AiContractException('AI_SCHEMA_MIN_ITEMS', $path);
            }
            if (isset($schema['maxItems']) && $count > (int) $schema['maxItems']) {
                throw new AiContractException('AI_SCHEMA_MAX_ITEMS', $path);
            }
            if (($schema['uniqueItems'] ?? false) === true) {
                $encoded = array_map(fn ($item) => json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $value);
                if (count($encoded) !== count(array_unique($encoded))) {
                    throw new AiContractException('AI_SCHEMA_UNIQUE_ITEMS', $path);
                }
            }
            if (isset($schema['items']) && is_array($schema['items'])) {
                foreach ($value as $index => $item) {
                    $this->validate($item, $schema['items'], $path . '[' . $index . ']', $rootSchema);
                }
            }
        }

        if (is_array($value) && ! array_is_list($value)) {
            $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
            foreach ((array) ($schema['required'] ?? []) as $required) {
                if (! array_key_exists($required, $value)) {
                    throw new AiContractException('AI_SCHEMA_REQUIRED', $path . '.' . $required);
                }
            }

            if (($schema['additionalProperties'] ?? true) === false) {
                foreach (array_keys($value) as $key) {
                    if (! array_key_exists($key, $properties)) {
                        throw new AiContractException('AI_SCHEMA_UNEXPECTED_PROPERTY', $path . '.' . $key);
                    }
                }
            }

            foreach ($properties as $key => $propertySchema) {
                if (array_key_exists($key, $value) && is_array($propertySchema)) {
                    $this->validate($value[$key], $propertySchema, $path . '.' . $key, $rootSchema);
                }
            }
        }
    }

    /** @param list<string> $types */
    private function matchesAnyType(mixed $value, array $types): bool
    {
        foreach ($types as $type) {
            $matches = match ($type) {
                'null' => $value === null,
                'string' => is_string($value),
                'integer' => is_int($value),
                'number' => is_int($value) || is_float($value),
                'boolean' => is_bool($value),
                'array' => is_array($value) && array_is_list($value),
                'object' => is_array($value) && ! array_is_list($value),
                default => false,
            };
            if ($matches) {
                return true;
            }
        }
        return false;
    }

    /** @param array<string,mixed> $root */
    private function resolveRef(string $ref, array $root): array
    {
        if (! str_starts_with($ref, '#/')) {
            throw new AiContractException('AI_SCHEMA_EXTERNAL_REF_NOT_SUPPORTED', '$', $ref);
        }

        $node = $root;
        foreach (explode('/', substr($ref, 2)) as $segment) {
            $segment = str_replace(['~1', '~0'], ['/', '~'], $segment);
            if (! is_array($node) || ! array_key_exists($segment, $node)) {
                throw new AiContractException('AI_SCHEMA_REF_NOT_FOUND', '$', $ref);
            }
            $node = $node[$segment];
        }

        if (! is_array($node)) {
            throw new AiContractException('AI_SCHEMA_REF_NOT_OBJECT', '$', $ref);
        }

        return $node;
    }

    private function isValidDate(string $value): bool
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date !== false && $date->format('Y-m-d') === $value;
    }
}
