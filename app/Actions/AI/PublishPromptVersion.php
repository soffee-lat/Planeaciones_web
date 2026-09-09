<?php

namespace App\Actions\AI;

use App\Models\PromptTemplate;
use App\Models\PromptVersion;
use App\Models\User;
use App\Services\AI\PromptRenderer;
use App\Support\AI\CanonicalJson;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use RuntimeException;

class PublishPromptVersion
{
    public function __construct(private PromptRenderer $renderer) {}

    public function execute(User $actor, PromptVersion $version, bool $activate = true): PromptVersion
    {
        Gate::forUser($actor)->authorize('publish', $version);

        return DB::transaction(function () use ($actor, $version, $activate): PromptVersion {
            /** @var PromptTemplate $template */
            $template = PromptTemplate::query()->whereKey($version->template_id)->lockForUpdate()->firstOrFail();
            /** @var PromptVersion $fresh */
            $fresh = PromptVersion::query()->whereKey($version->id)->lockForUpdate()->firstOrFail();

            if ($fresh->published_at !== null) {
                throw new RuntimeException('PROMPT_VERSION_ALREADY_PUBLISHED');
            }
            if ($fresh->template_id !== $template->id) {
                throw new RuntimeException('PROMPT_VERSION_TEMPLATE_MISMATCH');
            }

            $allowed = $this->normalizeVariables($fresh->allowed_variables ?? []);
            $this->renderer->assertTemplateVariablesAllowed($fresh->body, $allowed);
            if (! is_array($fresh->output_schema) || $fresh->output_schema === []) {
                throw new RuntimeException('PROMPT_VERSION_OUTPUT_SCHEMA_REQUIRED');
            }
            if (trim((string) $fresh->schema_version) === '') {
                throw new RuntimeException('PROMPT_VERSION_SCHEMA_VERSION_REQUIRED');
            }

            $checksum = CanonicalJson::hash([
                'template_key' => $template->key,
                'category' => $template->category->value,
                'number' => (int) $fresh->number,
                'body' => $fresh->body,
                'allowed_variables' => $allowed,
                'output_schema' => $fresh->output_schema,
                'schema_version' => $fresh->schema_version,
            ]);

            $fresh->forceFill([
                'allowed_variables' => $allowed,
                'published_at' => now(),
                'created_by' => $fresh->created_by ?? $actor->id,
                'checksum' => $checksum,
            ])->save();

            if ($activate) {
                $template->forceFill(['active_version_id' => $fresh->id])->save();
            }

            return $fresh->fresh(['template']);
        });
    }

    /** @param mixed $variables @return list<string> */
    private function normalizeVariables(mixed $variables): array
    {
        if (! is_array($variables)) {
            throw new RuntimeException('PROMPT_VERSION_ALLOWED_VARIABLES_INVALID');
        }

        $normalized = [];
        foreach ($variables as $variable) {
            $variable = trim((string) $variable);
            if ($variable === '' || preg_match('/^[A-Za-z_][A-Za-z0-9_.-]*$/', $variable) !== 1) {
                throw new RuntimeException('PROMPT_VERSION_ALLOWED_VARIABLE_INVALID:' . $variable);
            }
            $normalized[] = $variable;
        }

        $normalized = array_values(array_unique($normalized));
        sort($normalized, SORT_STRING);

        return $normalized;
    }
}
