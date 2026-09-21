<?php

namespace Tests\Feature\Documents;

use App\Actions\Documents\EnsureStandardFormat;
use App\Actions\Documents\PublishFormatVersion;
use App\Enums\FileScanStatus;
use App\Enums\InstitutionalFormatKind;
use App\Enums\InstitutionalFormatStatus;
use App\Exceptions\DocumentFormatException;
use App\Models\FormatVersion;
use App\Models\PlanningRequest;
use App\Models\StoredFile;
use App\Services\Documents\PlanningFormatResolver;
use Illuminate\Database\QueryException;
use RuntimeException;
use Tests\Concerns\CreatesInstitutionalFormatScenario;
use Tests\Feature\PedagogyTestCase;

class FormatFoundationTest extends PedagogyTestCase
{
    use CreatesInstitutionalFormatScenario;

    public function test_formato_estandar_es_idempotente_y_publicado(): void
    {
        $first = app(EnsureStandardFormat::class)->execute();
        $second = app(EnsureStandardFormat::class)->execute();
        $this->assertSame($first['format']->id, $second['format']->id);
        $this->assertSame($first['version']->id, $second['version']->id);
        $this->assertSame(InstitutionalFormatKind::Standard, $first['format']->kind);
        $this->assertSame(InstitutionalFormatStatus::Ready, $first['format']->status);
        $this->assertNotNull($first['version']->published_at);
        $this->assertSame('standard-v1', $first['version']->renderer);
    }

    public function test_publicacion_institucional_exige_fuente_limpia_y_muestra_aprobada(): void
    {
        $scene = $this->seedFullTeacher();
        $version = $this->configuredInstitutional($scene['user']->id);
        $sample = app(\App\Actions\Documents\RenderInstitutionalFormatSample::class)->execute($version, $scene['user']);
        app(\App\Actions\Documents\ReviewInstitutionalFormatSample::class)->approve($sample, $scene['user'], 'Muestra correcta.');
        $published = app(PublishFormatVersion::class)->execute($version->fresh(), $scene['user']);
        $this->assertNotNull($published->published_at);
        $this->assertSame(InstitutionalFormatStatus::Ready, $published->format->status);
        $this->assertSame($scene['user']->id, $published->approved_by);
    }

    public function test_publicacion_institucional_rechaza_actor_que_no_es_propietario(): void
    {
        $owner = $this->customer();
        $version = $this->configuredInstitutional($owner->id);
        $this->expectException(DocumentFormatException::class);
        $this->expectExceptionMessage('FORMAT_OWNER_REQUIRED');
        app(PublishFormatVersion::class)->execute($version, $this->customer());
    }

    public function test_resolver_usa_estandar_si_no_hay_preferencia(): void
    {
        $scene = $this->seedFullTeacher(); app(EnsureStandardFormat::class)->execute();
        $resolved = app(PlanningFormatResolver::class)->resolve($this->requestFor($scene));
        $this->assertSame('standard-v1', $resolved->renderer);
    }

    public function test_resolver_ignora_preferencia_del_grupo_sin_eleccion_explicita(): void
    {
        $scene = $this->seedFullTeacher();
        $standard = app(EnsureStandardFormat::class)->execute()['version'];
        $published = $this->publishedInstitutionalFormat($scene['user']->id);

        $scene['profile']->update(['preferred_format_id' => $published->format_id]);

        $resolved = app(PlanningFormatResolver::class)->resolve($this->requestFor($scene));

        $this->assertSame($standard->id, $resolved->id);
        $this->assertSame('standard-v1', $resolved->renderer);
    }

    public function test_version_explicita_de_solicitud_tiene_prioridad(): void
    {
        $scene = $this->seedFullTeacher(); app(EnsureStandardFormat::class)->execute();
        $preferred = $this->publishedInstitutionalFormat($scene['user']->id);
        $explicit = $this->publishedInstitutionalFormat($scene['user']->id);
        $scene['profile']->update(['preferred_format_id' => $preferred->format_id]);
        $request = $this->requestFor($scene); $request->update(['format_version_id' => $explicit->id]);
        $this->assertSame($explicit->id, app(PlanningFormatResolver::class)->resolve($request->fresh())->id);
    }

    public function test_version_publicada_es_inmutable(): void
    {
        $standard = app(EnsureStandardFormat::class)->execute()['version'];
        $this->expectException(RuntimeException::class); $this->expectExceptionMessage('FORMAT_VERSION_PUBLISHED_IMMUTABLE');
        $standard->update(['renderer' => 'otro-renderer']);
    }

    public function test_estado_de_escaneo_terminal_no_puede_reabrirse(): void
    {
        $file = StoredFile::factory()->create(['scan_status' => FileScanStatus::Clean->value]);
        $this->expectException(RuntimeException::class); $this->expectExceptionMessage('FILE_SCAN_STATUS_TERMINAL');
        $file->update(['scan_status' => FileScanStatus::Pending->value]);
    }

    public function test_bd_rechaza_preferencia_institucional_de_otro_docente(): void
    {
        $scene = $this->seedFullTeacher(); $foreign = $this->publishedInstitutionalFormat($this->customer()->id);
        $this->expectException(QueryException::class); $scene['profile']->update(['preferred_format_id' => $foreign->format_id]);
    }

    public function test_bd_rechaza_formato_explicito_ajeno_en_solicitud(): void
    {
        $scene = $this->seedFullTeacher(); $foreign = $this->publishedInstitutionalFormat($this->customer()->id); $request = $this->requestFor($scene);
        $this->expectException(QueryException::class); $request->update(['format_version_id' => $foreign->id]);
    }

    private function requestFor(array $scene): PlanningRequest
    {
        return PlanningRequest::factory()->create(['owner_id' => $scene['user']->id, 'group_id' => $scene['group']->id, 'curriculum_version_id' => $scene['version']->id, 'grade_id' => $scene['grade']->id]);
    }
}
