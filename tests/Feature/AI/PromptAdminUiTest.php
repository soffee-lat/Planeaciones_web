<?php

namespace Tests\Feature\AI;

use App\Actions\AI\PublishPromptVersion;
use App\Models\PromptTemplate;
use App\Models\PromptVersion;
use Filament\Facades\Filament;
use Tests\Feature\PedagogyTestCase;

class PromptAdminUiTest extends PedagogyTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_admin_abre_listados_y_formularios_de_prompts(): void
    {
        $this->actingAs($this->admin());

        $this->get('/admin/prompt-templates')->assertOk();
        $this->get('/admin/prompt-templates/create')->assertOk();
        $this->get('/admin/prompt-versions')->assertOk();
        $this->get('/admin/prompt-versions/create')->assertOk();
    }

    public function test_cliente_no_accede_a_gestion_de_prompts(): void
    {
        $this->actingAs($this->customer());
        $this->get('/admin/prompt-templates')->assertForbidden();
        $this->get('/admin/prompt-versions')->assertForbidden();
    }

    public function test_version_publicada_no_tiene_edicion_destructiva(): void
    {
        $admin = $this->admin();
        $template = PromptTemplate::factory()->create();
        $version = PromptVersion::factory()->create(['template_id' => $template->id]);
        app(PublishPromptVersion::class)->execute($admin, $version);
        $this->actingAs($admin);

        $this->get('/admin/prompt-versions/' . $version->id . '/edit')->assertForbidden();
    }
}
