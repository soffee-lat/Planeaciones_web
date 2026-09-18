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
use App\Support\AI\CanonicalJson;
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
                'body' => implode("\n", [
                    'Genera una planeación usando exclusivamente los datos de INPUT y respetando OUTPUT.',
                    'Si INPUT.group.planning_calendar contiene días, ese calendario es obligatorio:',
                    '- genera exactamente una sesión por cada bloque con include_in_planning=true;',
                    '- conserva las fechas y el orden de los bloques de cada día;',
                    '- session.date debe coincidir con la fecha del día del bloque;',
                    '- estimated_minutes debe ser exactamente igual a block.minutes;',
                    '- no generes sesiones para bloques con include_in_planning=false;',
                    '- no inventes, omitas ni traslades bloques a otras fechas;',
                    '- si un bloque no flexible contiene field_codes, la sesión debe usar al menos uno de esos campos;',
                    '- los nombres visibles del horario pueden ser propios de la escuela y no sustituyen el currículo oficial congelado.',
                    'Si INPUT.pedagogical_structure existe, también es obligatorio:',
                    '- respeta la división por semanas y desarrolla una progresión real entre los bloques de cada semana;',
                    '- cada bloque con primary_topics debe desarrollar esos temas como foco principal de esa materia;',
                    '- no inventes un tema principal diferente para una materia que ya tiene primary_topics asignados;',
                    '- un mismo tema puede continuar durante varios bloques o semanas: evita repetir la misma actividad y haz avanzar el aprendizaje;',
                    '- cuando block.transversal=true, usa available_transversal_topics sólo como refuerzo transversal cuando la conexión sea pedagógicamente pertinente;',
                    '- en un bloque transversal no inventes un tema principal nuevo sólo para llenar la materia;',
                    '- no fuerces conexiones artificiales: si una transversalidad no aporta, conserva la identidad de la materia y realiza un refuerzo sencillo y pertinente;',
                    '- integrative_project es un contexto común opcional; nunca sustituye los temas propios de cada materia;',
                    '- en planeaciones mensuales, organiza el desarrollo por semanas completas o parciales según pedagogical_structure.weeks.',
                    'INPUT={{input_snapshot}}',
                    'OUTPUT={{output_schema}}',
                ]),
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

        $schema = $this->loadSchema($definition['schema_path']);

        /** @var PromptVersion $version */
        $version = DB::transaction(function () use ($actor, $definition, $schema): PromptVersion {
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

                if ($this->matchesDefinition($active, $definition, $schema)) {
                    return $active;
                }
            }

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

    /**
     * @param array{category:PromptCategory,key:string,name:string,body:string,allowed_variables:list<string>,schema_version:string,schema_path:string} $definition
     * @param array<string,mixed> $schema
     */
    private function matchesDefinition(PromptVersion $version, array $definition, array $schema): bool
    {
        $storedVariables = array_values($version->allowed_variables ?? []);
        $expectedVariables = array_values($definition['allowed_variables']);
        sort($storedVariables, SORT_STRING);
        sort($expectedVariables, SORT_STRING);

        return $version->body === $definition['body']
            && $storedVariables === $expectedVariables
            && $version->schema_version === $definition['schema_version']
            && CanonicalJson::hash($version->output_schema ?? []) === CanonicalJson::hash($schema);
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
