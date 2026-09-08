<?php

namespace Tests\Feature;

use App\Models\Plan;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;

class PlanTest extends PedagogyTestCase
{
    use RefreshDatabase;

    #[Test]
    public function admin_puede_crear_plan(): void
    {
        $admin = $this->admin();
        $this->assertTrue($admin->can('create', Plan::class));
        $plan = Plan::factory()->create(['code' => 'X-1']);
        $this->assertDatabaseHas('plans', ['code' => 'X-1']);
    }

    #[Test]
    public function customer_no_puede_crear_plan(): void
    {
        $this->assertFalse($this->customer()->can('create', Plan::class));
    }

    #[Test]
    public function reviewer_no_puede_crear_plan(): void
    {
        $this->assertFalse($this->reviewer()->can('create', Plan::class));
    }

    #[Test]
    public function codigo_unico(): void
    {
        Plan::factory()->create(['code' => 'DUP-1']);
        $this->expectException(QueryException::class);
        Plan::factory()->create(['code' => 'DUP-1']);
    }
}
