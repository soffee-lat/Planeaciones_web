<?php

namespace Tests\Feature\Documents;

use App\Actions\Documents\PublishPlanningDelivery;
use App\Actions\Documents\PurgeExpiredDocumentFiles;
use App\Enums\PlanningRequestStatus;
use App\Models\DeliveryDownload;
use App\Models\PlanningDelivery;
use App\Models\StoredFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsGeneratedPlanDraft;
use Tests\Concerns\CreatesCommercialPlanningScenario;
use Tests\Concerns\CreatesManualAiPipelineScenario;
use Tests\Concerns\CreatesRenderedPlanningScenario;
use Tests\Feature\PedagogyTestCase;

class PrivateDeliveryTest extends PedagogyTestCase
{
    use BuildsGeneratedPlanDraft;
    use CreatesCommercialPlanningScenario;
    use CreatesManualAiPipelineScenario;
    use CreatesRenderedPlanningScenario;

    public function test_publicar_entrega_congela_version_archivos_y_pasa_a_entregada(): void
    {
        $scene = $this->renderedPlanningScene();

        $delivery = app(PublishPlanningDelivery::class)->execute(
            $scene['request'],
            null,
            'a1111111-1111-4111-8111-111111111111',
        );

        $this->assertSame(PlanningRequestStatus::ENTREGADA, $scene['request']->fresh()->status);
        $this->assertSame($scene['version']->id, $delivery->version_id);
        $this->assertCount(2, $delivery->files);
        $this->assertSame(['docx', 'pdf'], $delivery->files->pluck('pivot.output_format')->sort()->values()->all());
        foreach ($delivery->files as $file) {
            $this->assertNotNull($file->retention_until);
            $this->assertNull($file->purged_at);
        }
        $this->assertDatabaseHas('request_state_events', [
            'request_id' => $scene['request']->id,
            'from_status' => 'LISTA_PARA_ENTREGAR',
            'to_status' => 'ENTREGADA',
            'reason' => 'delivery_published',
            'correlation_id' => 'a1111111-1111-4111-8111-111111111111',
        ]);
    }

    public function test_publicar_misma_version_es_idempotente(): void
    {
        $scene = $this->renderedPlanningScene('documents/delivery-idempotent');
        $first = app(PublishPlanningDelivery::class)->execute($scene['request']);
        $second = app(PublishPlanningDelivery::class)->execute($scene['request']->fresh());

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, PlanningDelivery::query()->where('request_id', $scene['request']->id)->count());
        $this->assertSame(2, DB::table('delivery_files')->where('delivery_id', $first->id)->count());
    }

    public function test_propietario_descarga_archivo_y_se_registra_historial(): void
    {
        $scene = $this->renderedPlanningScene('documents/delivery-download');
        $delivery = app(PublishPlanningDelivery::class)->execute($scene['request']);
        $file = $delivery->files->first(fn ($candidate) => $candidate->pivot->output_format === 'pdf');

        $response = $this->actingAs($scene['request']->owner)->get(route('planning-deliveries.download', ['delivery' => $delivery->id, 'file' => $file->id]));

        $response->assertOk()->assertDownload($file->original_name);
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertDatabaseHas('delivery_downloads', [
            'delivery_id' => $delivery->id,
            'file_id' => $file->id,
            'user_id' => $scene['request']->owner_id,
        ]);
    }

    public function test_otro_usuario_no_puede_descargar_entrega_ajena(): void
    {
        $scene = $this->renderedPlanningScene('documents/delivery-foreign');
        $delivery = app(PublishPlanningDelivery::class)->execute($scene['request']);
        $file = $delivery->files->first();

        $this->actingAs($this->customer())
            ->get(route('planning-deliveries.download', ['delivery' => $delivery->id, 'file' => $file->id]))
            ->assertForbidden();

        $this->assertSame(0, DeliveryDownload::query()->where('delivery_id', $delivery->id)->count());
    }

    public function test_descarga_expirada_responde_410_aunque_el_byte_siga_en_storage(): void
    {
        $scene = $this->renderedPlanningScene('documents/delivery-expired');
        $delivery = app(PublishPlanningDelivery::class)->execute($scene['request']);
        $file = $delivery->files->first();
        $this->travel(366)->days();

        Storage::disk($file->disk)->assertExists($file->path);
        $this->actingAs($scene['request']->owner)
            ->get(route('planning-deliveries.download', ['delivery' => $delivery->id, 'file' => $file->id]))
            ->assertStatus(410);
        $this->assertSame(0, DeliveryDownload::query()->where('delivery_id', $delivery->id)->count());
    }

    public function test_purga_elimina_bytes_pero_conserva_metadatos_y_entrega(): void
    {
        $scene = $this->renderedPlanningScene('documents/delivery-purge');
        $delivery = app(PublishPlanningDelivery::class)->execute($scene['request']);
        $ids = $delivery->files->pluck('id')->all();
        $this->travel(366)->days();

        $result = app(PurgeExpiredDocumentFiles::class)->execute();

        $this->assertSame(2, $result['purged']);
        $this->assertSame(0, $result['failed']);
        foreach ($ids as $id) {
            $file = StoredFile::query()->findOrFail($id);
            Storage::disk($file->disk)->assertMissing($file->path);
            $this->assertNotNull($file->purged_at);
            $this->assertDatabaseHas('delivery_files', ['delivery_id' => $delivery->id, 'file_id' => $id]);
        }
        $this->assertDatabaseHas('deliveries', ['id' => $delivery->id, 'version_id' => $scene['version']->id]);
    }

    public function test_historial_permite_multiples_descargas_sin_mutar_entrega(): void
    {
        $scene = $this->renderedPlanningScene('documents/delivery-history');
        $delivery = app(PublishPlanningDelivery::class)->execute($scene['request']);
        $file = $delivery->files->first();

        $this->actingAs($scene['request']->owner)->get(route('planning-deliveries.download', ['delivery' => $delivery->id, 'file' => $file->id]))->assertOk();
        $this->actingAs($scene['request']->owner)->get(route('planning-deliveries.download', ['delivery' => $delivery->id, 'file' => $file->id]))->assertOk();

        $this->assertSame(2, DeliveryDownload::query()->where('delivery_id', $delivery->id)->where('file_id', $file->id)->count());
        $this->assertSame(1, PlanningDelivery::query()->whereKey($delivery->id)->count());
    }

    public function test_vista_del_docente_muestra_entrega_y_enlaces_privados(): void
    {
        $scene = $this->renderedPlanningScene('documents/delivery-ui');
        app(PublishPlanningDelivery::class)->execute($scene['request']);

        $this->actingAs($scene['request']->owner)
            ->get('/app/planning-requests/' . $scene['request']->id)
            ->assertOk()
            ->assertSee('Planeación lista')
            ->assertSee('Descargar DOCX')
            ->assertSee('Descargar PDF')
            ->assertSee('/app/deliveries/', false);
    }
}
