<?php

namespace Tests\Feature;

use App\Enums\SchoolType;
use App\Models\School;

class SchoolTest extends PedagogyTestCase
{
    public function test_cliente_crea_escuela_propia(): void
    {
        $user = $this->customer();

        $school = School::factory()->create([
            'owner_id' => $user->id,
            'school_type' => SchoolType::Public->value,
        ]);

        $this->assertSame($user->id, $school->owner_id);
        $this->assertSame(SchoolType::Public, $school->fresh()->school_type);
    }

    public function test_school_type_invalido_es_rechazado_por_check(): void
    {
        $user = $this->customer();
        $this->expectException(\Illuminate\Database\QueryException::class);
        // Bypass Eloquent enum cast to hit the PostgreSQL CHECK constraint directly.
        \Illuminate\Support\Facades\DB::table('schools')->insert([
            'owner_id' => $user->id,
            'name' => 'Bypass',
            'school_type' => 'inventado',
            'state' => 'CDMX',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_cliente_a_no_ve_escuela_del_cliente_b_via_policy(): void
    {
        $a = $this->customer();
        $b = $this->customer();
        $schoolB = School::factory()->create(['owner_id' => $b->id, 'school_type' => 'public']);

        $this->assertTrue($b->can('view', $schoolB));
        $this->assertFalse($a->can('view', $schoolB));
    }

    public function test_cliente_a_no_puede_modificar_escuela_del_cliente_b(): void
    {
        $a = $this->customer();
        $b = $this->customer();
        $schoolB = School::factory()->create(['owner_id' => $b->id, 'school_type' => 'public']);

        $this->assertFalse($a->can('update', $schoolB));
        $this->assertFalse($a->can('delete', $schoolB));
    }

    public function test_revisor_no_tiene_acceso_a_escuelas(): void
    {
        $rev = $this->reviewer();
        $school = School::factory()->create(['owner_id' => $this->customer()->id, 'school_type' => 'public']);

        $this->assertFalse($rev->can('viewAny', School::class));
        $this->assertFalse($rev->can('view', $school));
        $this->actingAs($rev)->get('/app/schools')->assertForbidden();
    }

    public function test_owner_id_manipulado_por_navegador_es_ignorado_en_creacion(): void
    {
        $a = $this->customer();
        $b = $this->customer();

        $this->actingAs($a)
            ->post('/app/schools', [
                // Filament CRUD requires livewire; this is a sanity check on the CreateSchool mutator.
                // We instead invoke the mutator via the create page unit-style.
            ]);

        // Direct assertion: CreateSchool page hard-overrides owner_id.
        $data = ['owner_id' => $b->id, 'name' => 'X', 'school_type' => 'public', 'state' => 'CDMX'];
        $page = new \App\Filament\App\Resources\Schools\Pages\CreateSchool();
        auth()->login($a);
        $reflected = new \ReflectionMethod($page, 'mutateFormDataBeforeCreate');
        $reflected->setAccessible(true);
        $mutated = $reflected->invoke($page, $data);
        $this->assertSame($a->id, $mutated['owner_id']);
    }

    public function test_admin_puede_ver_cualquier_escuela(): void
    {
        $admin = $this->admin();
        $customer = $this->customer();
        $school = School::factory()->create(['owner_id' => $customer->id, 'school_type' => 'public']);

        $this->assertTrue($admin->can('view', $school));
    }
}
