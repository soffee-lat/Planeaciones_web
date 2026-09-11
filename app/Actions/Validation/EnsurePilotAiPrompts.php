<?php

namespace App\Actions\Validation;

use App\Actions\AI\PublishPromptVersion;
use App\Enums\PromptCategory;
use App\Enums\RoleCode;
use App\Models\PromptTemplate;
use App\Models\PromptVersion;
use App\Models\User;
use App\Services\AI\AuditPromptPolicy;
use App\Services\AI\AuditResultValidator;
use App\Services\AI\CorrectionPromptPolicy;
use App\Services\AI\CorrectionResultValidator;
use App\Services\AI\GeneratedPlanDraftValidator;
use App\Services\AI\GenerationPromptPolicy;
use Illuminate\Support\Facades\DB;
use JsonException;
use RuntimeException;

final class EnsurePilotAiPrompts
{
    public function __construct(
        private PublishPromptVersion $publisher,
        private GenerationPromptPolicy $generationPolicy,
        private AuditPromptPolicy $auditPolicy,
        private CorrectionPromptPolicy $correctionPolicy,
    ) {}

    /** @return array{generation:PromptVersion,audit:PromptVersion,correction:PromptVersion} */
    public function execute(User $actor): array
    {
        if ($actor->status !== 'active' || ! $actor->hasRole(RoleCode::Administrator)) {
            throw new RuntimeException('PILOT_AI_PROMPTS_ADMIN_REQUIRED');
        }

        $definitions = [
            'generation' => [
                'category' => PromptCategory::Generation,
                'key' => trim((string) config('ai.prompts.generation_key', 'planning.generation')),
                'name' => 'Generación de planeación',
                'body' => "INPUT={{input_snapshot}}\nOUTPUT={{output_schema}}",
                'allowed_variables' => ['input_snapshot', 'output_schema'],
                'schema_version' => GeneratedPlanDraftValidator::CONTRACT_VERSION,
                'schema_path' => resource_path('schemas/ai/generated_plan_draft_v1.schema.json'),
            ],
            'audit' => [
                'category' => PromptCategory::Audit,
                'key' => trim((string) config('ai.prompts.audit_key', 'planning.audit')),
                'name' => 'Auditoría de planeación',
                'body' => "CANONICAL={{canonical_plan}}\nOUTPUT={{output_schema}}",
                'allowed_variables' => ['canonical_plan', 'output_schema'],
                'schema_version' => AuditResultValidator::CONTRACT_VERSION,
                'schema_path' => resource_path('schemas/ai/audit_result_v1.schema.json'),
            ],
            'correction' => [
                'category' => PromptCategory::Correction,
                'key' => trim((string) config('ai.prompts.correction_key', 'planning.correction')),
                'name' => 'Corrección de planeación',
                'body' => "CANONICAL={{canonical_plan}}\nAUDIT={{audit_report}}\nSCOPE={{section_keys}}\nOUTPUT={{output_schema}}",
                'allowed_variables' => ['canonical_plan', 'audit_report', 'section_keys', 'output_schema'],
                'schema_version' => CorrectionResultValidator::CONTRACT_VERSION,
                'schema_path' => resource_path('schemas/ai/correction_result_v1.schema.json'),
            ],
        ];

        $result = [];
        foreach ($definitions as $name => $definition) {
            $result[$name] = $this->ensure($actor, $definition);
        }

        return $result;
    }

    /** @param array{category:PromptCategory,key:string,name:string,body:string,allowed_variables:list<string>,schema_version:string,schema_path:string} $definition */
    private function ensure(User $actor, array $definition): PromptVersion
    {
        if ($definition['key'] === '') {
            throw new RuntimeException('PILOT_AI_PROMPT_KEY_INVALID');
        }

        /** @var PromptVersion $version */
        $version = DB::transaction(function () use ($actor, $definition): PromptVersion {
            $template = PromptTemplate::query()->where('key', $definition['key'])->lockForUpdate()->first();
            if ($template && $template->category !== $definition['category']) {
                throw new RuntimeException('PILOT_AI_PROMPT_CATEGORY_MISMATCH:' . $definition['key']);
            }

            $template ??= PromptTemplate::query()->create([
                'key' => $definition['key'],
                'category' => $definition['category']->value,
                'name' => $definition['name'],
                'active_version_id' => null,
            ]);

            if ($template->active_version_id !== null) {
                $active = PromptVersion::query()
                    ->whereKey($template->active_version_id)
                    ->where('template_id', $template->id)
                    ->first();
                if (! $active) {
                    throw new RuntimeException('PILOT_AI_PROMPT_ACTIVE_VERSION_INVALID:' . $definition['key']);
                }

                return $active;
            }

            $schema = $this->loadSchema($definition['schema_path']);
            $number = ((int) PromptVersion::query()->where('template_id', $template->id)->max('number')) + 1;
            $draft = PromptVersion::query()->create([
                'template_id' => $template->id,
                'number' => max(1, $number),
                'body' => $definition['body'],
                'allowed_variables' => $definition['allowed_variables'],
                'output_schema' => $schema,
                'schema_version' => $definition['schema_version'],
                'created_by' => $actor->id,
            ]);

            return $this->publisher->execute($actor, $draft, true);
        }, attempts: 3);

        match ($definition['category']) {
            PromptCategory::Generation => $this->generationPolicy->assertReady($version),
            PromptCategory::Audit => $this->auditPolicy->assertReady($version),
            PromptCategory::Correction => $this->correctionPolicy->assertReady($version),
            default => throw new RuntimeException('PILOT_AI_PROMPT_CATEGORY_UNSUPPORTED'),
        };

        return $version->fresh(['template']);
    }

    /** @return array<string,mixed> */
    private function loadSchema(string $path): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException('PILOT_AI_PROMPT_SCHEMA_UNREADABLE:' . basename($path));
        }

        try {
            $schema = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('PILOT_AI_PROMPT_SCHEMA_INVALID:' . basename($path));
        }

        if (! is_array($schema) || $schema === []) {
            throw new RuntimeException('PILOT_AI_PROMPT_SCHEMA_INVALID:' . basename($path));
        }

        return $schema;
    }
}
