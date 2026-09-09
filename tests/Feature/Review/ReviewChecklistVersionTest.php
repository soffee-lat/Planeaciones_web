<?php

namespace Tests\Feature\Review;

use App\Actions\Review\PublishReviewChecklistVersion;
use App\Enums\ReviewChecklistVersionStatus;
use App\Exceptions\HumanReviewException;
use App\Models\ReviewChecklistItem;
use App\Models\ReviewChecklistVersion;
use Tests\Feature\PedagogyTestCase;

class ReviewChecklistVersionTest extends PedagogyTestCase
{
    public function test_migracion_publica_checklist_estandar_v1_con_quince_claves(): void
    {
        $version = ReviewChecklistVersion::query()->where('key', 'standard')->where('version', 1)->firstOrFail();

        $this->assertSame(ReviewChecklistVersionStatus::Published, $version->status);
        $this->assertCount(15, $version->items);
        $this->assertSame([
            'grade','dates','contents','pda','fields','axes','coherence','difficulty','activities','moments','assessment','materials','transversal','orthography','format',
        ], $version->items->pluck('key')->all());
    }

    public function test_admin_publica_version_borrador_y_queda_inmutable(): void
    {
        $admin = $this->admin();
        $version = ReviewChecklistVersion::query()->create([
            'key' => 'standard', 'version' => 2, 'name' => 'V2', 'status' => 'draft', 'created_by' => $admin->id,
        ]);
        ReviewChecklistItem::query()->create([
            'checklist_version_id' => $version->id,
            'key' => 'grade', 'label' => 'Grado', 'required' => true, 'sort_order' => 10,
        ]);

        $published = app(PublishReviewChecklistVersion::class)->execute($admin, $version);
        $this->assertSame(ReviewChecklistVersionStatus::Published, $published->status);
        $this->assertNotNull($published->published_at);

        $this->expectException(\Illuminate\Database\QueryException::class);
        ReviewChecklistItem::query()->where('checklist_version_id', $version->id)->update(['label' => 'Manipulado']);
    }

    public function test_revisor_no_puede_publicar_checklist(): void
    {
        $version = ReviewChecklistVersion::query()->create([
            'key' => 'alternate', 'version' => 1, 'name' => 'Alterno', 'status' => 'draft', 'created_by' => null,
        ]);
        ReviewChecklistItem::query()->create([
            'checklist_version_id' => $version->id,
            'key' => 'grade', 'label' => 'Grado', 'required' => true, 'sort_order' => 10,
        ]);

        $this->expectException(HumanReviewException::class);
        $this->expectExceptionMessage('HUMAN_REVIEW_CHECKLIST_PUBLISH_FORBIDDEN');
        app(PublishReviewChecklistVersion::class)->execute($this->reviewer(), $version);
    }
}
