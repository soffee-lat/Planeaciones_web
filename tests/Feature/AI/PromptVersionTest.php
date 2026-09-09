<?php

namespace Tests\Feature\AI;

use App\Actions\AI\PublishPromptVersion;
use App\Enums\PromptCategory;
use App\Models\PromptTemplate;
use App\Models\PromptVersion;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Feature\PedagogyTestCase;

class PromptVersionTest extends PedagogyTestCase
{
    private function schema(): array
    {
        return json_decode(file_get_contents(resource_path('schemas/ai/generated_plan_draft_v1.schema.json')), true, 512, JSON_THROW_ON_ERROR);
    }

    public function test_admin_crea_y_publica_version_que_se_vuelve_activa(): void
    {
        $admin = $this->admin();
        $template = PromptTemplate::factory()->create(['category' => PromptCategory::Generation->value]);
        $version = PromptVersion::factory()->create([
            'template_id' => $template->id,
            'number' => 1,
            'created_by' => $admin->id,
        ]);

        $published = app(PublishPromptVersion::class)->execute($admin, $version);

        $this->assertTrue($published->isPublished());
        $this->assertSame(64, strlen((string) $published->checksum));
        $this->assertSame($published->id, $template->fresh()->active_version_id);
    }

    public function test_draft_es_editable(): void
    {
        $version = PromptVersion::factory()->create();
        $version->update(['body' => 'Nuevo cuerpo {{input_snapshot}} {{output_schema}}']);
        $this->assertStringStartsWith('Nuevo cuerpo', $version->fresh()->body);
    }

    public function test_publicada_es_inmutable_via_eloquent(): void
    {
        $admin = $this->admin();
        $version = PromptVersion::factory()->create();
        app(PublishPromptVersion::class)->execute($admin, $version);

        $published = $version->fresh();
        $published->body = 'Cambio ilegal';
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('PROMPT_VERSION_PUBLISHED_IMMUTABLE');
        $published->save();
    }

    public function test_publicada_es_inmutable_via_postgresql(): void
    {
        $admin = $this->admin();
        $version = PromptVersion::factory()->create();
        app(PublishPromptVersion::class)->execute($admin, $version);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('PROMPT_VERSION_PUBLISHED_IMMUTABLE');
        DB::table('prompt_versions')->where('id', $version->id)->update(['body' => 'Cambio directo']);
    }

    public function test_publicada_no_puede_eliminarse_via_postgresql(): void
    {
        $admin = $this->admin();
        $version = PromptVersion::factory()->create();
        app(PublishPromptVersion::class)->execute($admin, $version);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('PROMPT_VERSION_PUBLISHED_IMMUTABLE');
        DB::table('prompt_versions')->where('id', $version->id)->delete();
    }

    public function test_nueva_version_coexiste_con_historica_y_puede_activarse(): void
    {
        $admin = $this->admin();
        $template = PromptTemplate::factory()->create();
        $v1 = PromptVersion::factory()->create(['template_id' => $template->id, 'number' => 1]);
        app(PublishPromptVersion::class)->execute($admin, $v1);
        $v2 = PromptVersion::factory()->create([
            'template_id' => $template->id,
            'number' => 2,
            'body' => 'Segunda versión {{input_snapshot}} {{output_schema}}',
        ]);

        app(PublishPromptVersion::class)->execute($admin, $v2);

        $this->assertTrue($v1->fresh()->isPublished());
        $this->assertTrue($v2->fresh()->isPublished());
        $this->assertSame($v2->id, $template->fresh()->active_version_id);
    }

    public function test_numero_es_unico_por_template(): void
    {
        $template = PromptTemplate::factory()->create();
        PromptVersion::factory()->create(['template_id' => $template->id, 'number' => 1]);
        $this->expectException(QueryException::class);
        PromptVersion::factory()->create(['template_id' => $template->id, 'number' => 1]);
    }

    public function test_no_publica_placeholder_fuera_de_allowlist(): void
    {
        $admin = $this->admin();
        $version = PromptVersion::factory()->create([
            'body' => 'Usa {{input_snapshot}} y filtra {{secret_key}}.',
            'allowed_variables' => ['input_snapshot'],
        ]);

        $this->expectExceptionMessage('PROMPT_TEMPLATE_VARIABLE_NOT_ALLOWED');
        app(PublishPromptVersion::class)->execute($admin, $version);
    }

    public function test_checksum_permanece_congelado_tras_publicacion(): void
    {
        $admin = $this->admin();
        $version = PromptVersion::factory()->create([
            'allowed_variables' => ['output_schema', 'input_snapshot', 'input_snapshot'],
        ]);
        $published = app(PublishPromptVersion::class)->execute($admin, $version);
        $checksum = $published->checksum;

        $this->assertSame(['input_snapshot', 'output_schema'], $published->allowed_variables);
        $this->assertSame($checksum, $published->fresh()->checksum);
    }

    public function test_bd_rechaza_active_version_de_otro_template(): void
    {
        $admin = $this->admin();
        $a = PromptTemplate::factory()->create();
        $b = PromptTemplate::factory()->create();
        $versionB = PromptVersion::factory()->create(['template_id' => $b->id]);
        app(PublishPromptVersion::class)->execute($admin, $versionB);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('PROMPT_ACTIVE_VERSION_TEMPLATE_MISMATCH');
        DB::table('prompt_templates')->where('id', $a->id)->update(['active_version_id' => $versionB->id]);
    }

    public function test_identidad_de_template_se_congela_cuando_existen_versiones(): void
    {
        $template = PromptTemplate::factory()->create(['key' => 'generation.stable']);
        PromptVersion::factory()->create(['template_id' => $template->id]);

        $template->key = 'generation.changed';
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('PROMPT_TEMPLATE_IDENTITY_IMMUTABLE');
        $template->save();
    }

    public function test_bd_tambien_protege_identidad_de_template(): void
    {
        $template = PromptTemplate::factory()->create(['key' => 'generation.db-stable']);
        PromptVersion::factory()->create(['template_id' => $template->id]);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('PROMPT_TEMPLATE_IDENTITY_IMMUTABLE');
        DB::table('prompt_templates')->where('id', $template->id)->update(['category' => 'audit']);
    }

    public function test_customer_y_reviewer_no_gestionan_prompts(): void
    {
        $template = PromptTemplate::factory()->create();
        $version = PromptVersion::factory()->create(['template_id' => $template->id]);

        $this->assertFalse($this->customer()->can('view', $template));
        $this->assertFalse($this->reviewer()->can('create', PromptVersion::class));
        $this->assertFalse($this->customer()->can('publish', $version));
        $this->assertTrue($this->admin()->can('publish', $version));
    }
}
