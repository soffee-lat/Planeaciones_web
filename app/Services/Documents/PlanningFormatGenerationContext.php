<?php

namespace App\Services\Documents;

use App\Enums\InstitutionalFormatStatus;
use App\Exceptions\AiPipelineException;
use App\Exceptions\DocumentFormatException;
use App\Models\FormatVersion;
use App\Models\InstitutionalFormat;
use App\Models\PlanningRequest;

final class PlanningFormatGenerationContext
{
    public function __construct(
        private InstitutionalDynamicFieldResolver $dynamicFields,
        private InstitutionalFormatMapping $mapping,
    ) {}

    /** @return array<string,mixed> */
    public function build(PlanningRequest $request): array
    {
        $version = $this->resolveVersion($request);
        if (! $version || $version->renderer !== InstitutionalDocumentRenderer::FORMAT_RENDERER) {
            return $this->emptyContext();
        }

        try {
            $augmented = $this->dynamicFields->augment($version, is_array($version->mapping) ? $version->mapping : []);
            $normalized = $this->mapping->validate($version, $augmented);
        } catch (DocumentFormatException $e) {
            throw new AiPipelineException('AI_GENERATION_FORMAT_CONTEXT_INVALID', $e->getMessage());
        }

        $usedPaths = $this->usedPaths($normalized);
        $examples = $this->examplesByPath($version, $normalized);
        $customFields = [];

        foreach ((array) ($normalized['custom_fields'] ?? []) as $key => $definition) {
            if (! is_array($definition)) {
                continue;
            }
            $path = 'custom.' . (string) $key;
            if (! isset($usedPaths[$path])) {
                continue;
            }

            $label = trim((string) ($definition['label'] ?? $key));
            $source = $this->manualOnly($label) ? 'manual' : 'ai';
            $customFields[] = [
                'key' => (string) $key,
                'path' => $path,
                'label' => $label,
                'type' => (string) ($definition['type'] ?? 'long_text'),
                'instruction' => trim((string) ($definition['instruction'] ?? '')),
                'source' => $source,
                'required' => $source === 'ai',
                'example' => $source === 'ai' ? ($examples[$path] ?? null) : null,
            ];
        }

        usort($customFields, static fn (array $a, array $b): int => strcmp((string) $a['key'], (string) $b['key']));

        $standardPaths = array_values(array_keys(array_filter(
            $usedPaths,
            static fn (bool $_, string $path): bool => ! str_starts_with($path, 'custom.'),
            ARRAY_FILTER_USE_BOTH,
        )));
        $standardExamples = array_filter(
            $examples,
            static fn (string $_, string $path): bool => ! str_starts_with($path, 'custom.'),
            ARRAY_FILTER_USE_BOTH,
        );
        ksort($standardExamples, SORT_STRING);

        return [
            'schema_version' => 1,
            'format_version_id' => (int) $version->id,
            'format_name' => (string) ($version->format?->name ?? 'Formato institucional'),
            'renderer' => (string) $version->renderer,
            'source_content_mode' => data_get($version->validation_report, 'analysis.source_content_mode'),
            'mapped_standard_paths' => $standardPaths,
            'standard_field_examples' => $standardExamples,
            'custom_fields' => $customFields,
            'example_policy' => 'Los ejemplos sirven solo para entender intención, longitud, organización y estilo. No copies nombres, datos personales ni contenido específico de una planeación anterior.',
        ];
    }

    /** @return array<string,mixed> */
    private function emptyContext(): array
    {
        return [
            'schema_version' => 1,
            'format_version_id' => null,
            'format_name' => null,
            'renderer' => null,
            'source_content_mode' => null,
            'mapped_standard_paths' => [],
            'standard_field_examples' => [],
            'custom_fields' => [],
            'example_policy' => null,
        ];
    }

    private function resolveVersion(PlanningRequest $request): ?FormatVersion
    {
        if ($request->format_version_id !== null) {
            $version = FormatVersion::query()->with('format')->find($request->format_version_id);
            if (! $version || ! $this->usableFor($version, (int) $request->owner_id)) {
                throw new AiPipelineException('AI_GENERATION_FORMAT_VERSION_NOT_USABLE');
            }
            return $version;
        }

        $request->loadMissing('group.profile');
        $preferredId = $request->group?->profile?->preferred_format_id;
        if ($preferredId === null) {
            return null;
        }

        $format = InstitutionalFormat::query()
            ->whereKey($preferredId)
            ->where('status', InstitutionalFormatStatus::Ready->value)
            ->where(function ($query) use ($request): void {
                $query->whereNull('owner_id')->orWhere('owner_id', $request->owner_id);
            })
            ->first();
        if (! $format) {
            throw new AiPipelineException('AI_GENERATION_PREFERRED_FORMAT_NOT_USABLE');
        }

        $version = FormatVersion::query()
            ->with('format')
            ->where('format_id', $format->id)
            ->whereNotNull('published_at')
            ->orderByDesc('number')
            ->first();

        if (! $version || ! $this->usableFor($version, (int) $request->owner_id)) {
            throw new AiPipelineException('AI_GENERATION_PREFERRED_FORMAT_VERSION_MISSING');
        }

        return $version;
    }

    private function usableFor(FormatVersion $version, int $ownerId): bool
    {
        $format = $version->format;

        return $version->published_at !== null
            && $format !== null
            && $format->status === InstitutionalFormatStatus::Ready
            && ($format->owner_id === null || (int) $format->owner_id === $ownerId);
    }

    /** @param array<string,mixed> $mapping @return array<string,bool> */
    private function usedPaths(array $mapping): array
    {
        $paths = [];
        foreach (['anchors', 'placeholders'] as $group) {
            foreach ((array) ($mapping[$group] ?? []) as $path) {
                $path = trim((string) $path);
                if ($path !== '') {
                    $paths[$path] = true;
                }
            }
        }
        foreach ((array) ($mapping['fragments'] ?? []) as $fragment) {
            if (! is_array($fragment)) {
                continue;
            }
            $path = trim((string) ($fragment['field_path'] ?? ''));
            if ($path !== '') {
                $paths[$path] = true;
            }
        }
        ksort($paths, SORT_STRING);

        return $paths;
    }

    /** @param array<string,mixed> $mapping @return array<string,string> */
    private function examplesByPath(FormatVersion $version, array $mapping): array
    {
        $anchorPaths = is_array($mapping['anchors'] ?? null) ? $mapping['anchors'] : [];
        $examples = [];

        foreach ((array) data_get($version->validation_report, 'analysis.anchors', []) as $anchor) {
            if (! is_array($anchor)) {
                continue;
            }
            $id = (string) ($anchor['id'] ?? '');
            $path = (string) ($anchorPaths[$id] ?? '');
            $label = trim((string) ($anchor['label'] ?? ''));
            $example = trim((string) ($anchor['current_value_excerpt'] ?? ''));
            if ($path === '' || $example === '' || $this->manualOnly($label)) {
                continue;
            }
            $examples[$path] ??= mb_substr($example, 0, 160);
        }

        ksort($examples, SORT_STRING);
        return $examples;
    }

    private function manualOnly(string $label): bool
    {
        $text = mb_strtolower($label);

        foreach ([
            'firma', 'sello', 'curp', 'telefono', 'teléfono', 'direccion', 'dirección',
            'nombre del alumno', 'nombre de alumno', 'diagnostico', 'diagnóstico',
            'folio', 'autorizacion', 'autorización',
        ] as $term) {
            if (str_contains($text, $term)) {
                return true;
            }
        }

        return false;
    }
}
