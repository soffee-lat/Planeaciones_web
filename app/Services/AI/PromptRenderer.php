<?php

namespace App\Services\AI;

use App\Exceptions\AiContractException;
use App\Models\PromptVersion;

class PromptRenderer
{
    /** @param array<string,string|int|float|bool|null> $variables */
    public function render(PromptVersion $version, array $variables): string
    {
        $allowed = array_values(array_unique(array_map('strval', $version->allowed_variables ?? [])));
        $this->assertTemplateVariablesAllowed($version->body, $allowed);

        foreach (array_keys($variables) as $name) {
            if (! in_array($name, $allowed, true)) {
                throw new AiContractException('PROMPT_VARIABLE_NOT_ALLOWED', '$', $name);
            }
        }

        $missing = [];
        $rendered = preg_replace_callback(
            '/{{\s*([A-Za-z_][A-Za-z0-9_.-]*)\s*}}/',
            function (array $match) use ($variables, &$missing): string {
                $name = $match[1];
                if (! array_key_exists($name, $variables)) {
                    $missing[] = $name;
                    return $match[0];
                }
                $value = $variables[$name];
                if (is_bool($value)) {
                    return $value ? 'true' : 'false';
                }
                return $value === null ? '' : (string) $value;
            },
            $version->body,
        );

        if ($rendered === null) {
            throw new AiContractException('PROMPT_RENDER_FAILED');
        }
        if ($missing !== []) {
            throw new AiContractException('PROMPT_VARIABLE_MISSING', '$', implode(',', array_values(array_unique($missing))));
        }

        return $rendered;
    }

    /** @param list<string> $allowed */
    public function assertTemplateVariablesAllowed(string $body, array $allowed): void
    {
        preg_match_all('/{{\s*([A-Za-z_][A-Za-z0-9_.-]*)\s*}}/', $body, $matches);
        foreach (array_values(array_unique($matches[1] ?? [])) as $name) {
            if (! in_array($name, $allowed, true)) {
                throw new AiContractException('PROMPT_TEMPLATE_VARIABLE_NOT_ALLOWED', '$', $name);
            }
        }
    }
}
