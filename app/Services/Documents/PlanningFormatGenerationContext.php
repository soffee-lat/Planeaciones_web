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
        private GenericInstitutionalFieldResolver $dynamicFields,
        private InstitutionalFormatMapping $mapping,
        private InstitutionalTemplateContract $templateContract,
    ) {}

    /** @return array<string,mixed> */
    public function build(PlanningRequest $request): array
    {
        $version = $this->resolveVersion($request);
        if (! $version) {
            return $this->emptyContext();
        }
        $version->loadMissing(['format', 'sourceFile']);
        if ($version->renderer !== InstitutionalDocumentRenderer::FORMAT_RENDERER) {
            return $this->baseContext($version);
        }

        try {
            $augmented = $this->dynamicFields->augment($version, is_array($version->mapping) ? $version->mapping : []);
            $normalized = $this->mapping->validate($version, $augmented);
        } catch (DocumentFormatException $e) {
            throw new AiPipelineException('AI_GENERATION_FORMAT_CONTEXT_INVALID', $e->getMessage());
        }

        $contract = $this->templateContract->build($version, $normalized);
        $customDefinitions = is_array($normalized['custom_fields'] ?? null) ? $normalized['custom_fields'] : [];
        $customFields = [];
        $standardPaths = [];
        $standardExamples = [];

        foreach ((array) ($contract['fields'] ?? []) as $field) {
            if (! is_array($field)) {
                continue;
            }
            $path = trim((string) ($field['path'] ?? ''));
            if ($path === '') {
                continue;
            }

            if (! str_starts_with($path, 'custom.')) {
                $standardPaths[$path] = true;
                $example = trim((string) ($field['example'] ?? ''));
                if ($example !== '') {
                    $standardExamples[$path] ??= $example;
                }
                continue;
            }

            $key = substr($path, strlen('custom.'));
            $definition = is_array($customDefinitions[$key] ?? null) ? $customDefinitions[$key] : [];
            if ($key === '' || isset($customFields[$key])) {
                continue;
            }

            $customFields[$key] = $this->customFieldContext($key, $path, $definition, $field);
        }

        // Las estructuras repetibles no necesariamente tienen un anchor propio:
        // viven en `structures` y aun así deben formar parte del contrato de
        // generación. Por eso incorporamos cualquier definición custom que el
        // contrato estructural use y que todavía no aparezca como campo simple.
        foreach ($customDefinitions as $key => $definition) {
            if (! is_array($definition) || isset($customFields[$key])) {
                continue;
            }
            $usedByStructure = false;
            foreach ((array) ($normalized['structures'] ?? []) as $structure) {
                if (is_array($structure) && (string) ($structure['field_path'] ?? '') === 'custom.' . $key) {
                    $usedByStructure = true;
                    break;
                }
            }
            if (! $usedByStructure) {
                continue;
            }
            $customFields[$key] = $this->customFieldContext((string) $key, 'custom.' . $key, $definition, []);
        }

        ksort($customFields, SORT_STRING);
        ksort($standardPaths, SORT_STRING);
        ksort($standardExamples, SORT_STRING);

        return [
            ...$this->baseContext($version),
            'schema_version' => max(2, (int) ($normalized['schema_version'] ?? 2)),
            'source_content_mode' => data_get($version->validation_report, 'analysis.source_content_mode'),
            'mapped_standard_paths' => array_keys($standardPaths),
            'standard_field_examples' => $standardExamples,
            'custom_fields' => array_values($customFields),
            'template_contract' => $contract,
            'example_policy' => 'Los ejemplos sirven solo para entender intención, longitud, organización y estilo. No copies nombres, datos personales ni contenido específico de una planeación anterior.',
        ];
    }

    /** @param array<string,mixed> $definition @param array<string,mixed> $field @return array<string,mixed> */
    private function customFieldContext(string $key, string $path, array $definition, array $field): array
    {
        return [
            'key' => $key,
            'path' => $path,
            'label' => (string) ($field['label'] ?? $definition['label'] ?? $key),
            'type' => (string) ($field['type'] ?? $definition['type'] ?? 'long_text'),
            'instruction' => trim((string) ($field['instruction'] ?? $definition['instruction'] ?? '')),
            'source' => (string) ($field['source'] ?? 'ai'),
            'required' => (bool) ($field['required'] ?? true),
            'example' => $field['example'] ?? null,
            'item_fields' => is_array($definition['item_fields'] ?? null) ? $definition['item_fields'] : [],
        ];
    }

    /** @return array<string,mixed> */
    private function baseContext(FormatVersion $version): array
    {
        return [
            'schema_version' => 2,
            'format_version_id' => (int) $version->id,
            'format_name' => (string) ($version->format?->name ?? 'Formato de planeación'),
            'renderer' => (string) $version->renderer,
            'source_content_mode' => null,
            'mapped_standard_paths' => [],
            'standard_field_examples' => [],
            'custom_fields' => [],
            'template_contract' => null,
            'example_policy' => null,
        ];
    }

    /** @return array<string,mixed> */
    private function emptyContext(): array
    {
        return [
            'schema_version' => 2,
            'format_version_id' => null,
            'format_name' => null,
            'renderer' => null,
            'source_content_mode' => null,
            'mapped_standard_paths' => [],
            'standard_field_examples' => [],
            'custom_fields' => [],
            'template_contract' => null,
            'example_policy' => null,
        ];
    }

    private function resolveVersion(PlanningRequest $request): ?FormatVersion
    {
        if ($request->format_version_id !== null) {
            $version = FormatVersion::query()->with(['format', 'sourceFile'])->find($request->format_version_id);
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
            ->with(['format', 'sourceFile'])
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
}
