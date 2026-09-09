<?php

namespace Tests\Unit;

use App\Exceptions\AiContractException;
use App\Models\PromptVersion;
use App\Services\AI\PromptRenderer;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PromptRendererTest extends TestCase
{
    #[Test]
    public function sustituye_solo_variables_permitidas_sin_blade_ni_eval(): void
    {
        $version = new PromptVersion([
            'body' => 'Entrada={{input_snapshot}} | Schema={{output_schema}}',
            'allowed_variables' => ['input_snapshot', 'output_schema'],
        ]);

        $rendered = (new PromptRenderer())->render($version, [
            'input_snapshot' => '{"request":1}',
            'output_schema' => '{"type":"object"}',
        ]);

        $this->assertSame('Entrada={"request":1} | Schema={"type":"object"}', $rendered);
    }

    #[Test]
    public function rechaza_variable_de_runtime_fuera_de_allowlist(): void
    {
        $version = new PromptVersion([
            'body' => 'Entrada={{input_snapshot}}',
            'allowed_variables' => ['input_snapshot'],
        ]);

        $this->expectException(AiContractException::class);
        $this->expectExceptionMessage('PROMPT_VARIABLE_NOT_ALLOWED');
        (new PromptRenderer())->render($version, ['input_snapshot' => 'ok', 'secret' => 'no']);
    }

    #[Test]
    public function rechaza_placeholder_no_declarado(): void
    {
        $version = new PromptVersion([
            'body' => 'Entrada={{input_snapshot}} secreto={{secret}}',
            'allowed_variables' => ['input_snapshot'],
        ]);

        $this->expectExceptionMessage('PROMPT_TEMPLATE_VARIABLE_NOT_ALLOWED');
        (new PromptRenderer())->render($version, ['input_snapshot' => 'ok']);
    }

    #[Test]
    public function rechaza_variable_requerida_ausente(): void
    {
        $version = new PromptVersion([
            'body' => 'Entrada={{input_snapshot}}',
            'allowed_variables' => ['input_snapshot'],
        ]);

        $this->expectExceptionMessage('PROMPT_VARIABLE_MISSING');
        (new PromptRenderer())->render($version, []);
    }
}
