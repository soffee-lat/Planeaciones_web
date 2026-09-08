<?php

namespace Tests\Feature;

use App\Actions\Pedagogy\UpdateGroupProfile;
use App\Models\GroupProfile;
use Illuminate\Database\QueryException;

class GroupProfileTest extends PedagogyTestCase
{
    public function test_relacion_uno_a_uno_no_permite_perfil_duplicado(): void
    {
        $seed = $this->seedFullTeacher();

        $this->expectException(QueryException::class);
        GroupProfile::factory()->create(['group_id' => $seed['group']->id]);
    }

    public function test_modificacion_de_campo_pedagogico_incrementa_revision(): void
    {
        $seed = $this->seedFullTeacher();
        $profile = $seed['profile'];
        $initial = (int) $profile->revision;

        app(UpdateGroupProfile::class)->execute($seed['user'], $profile, [
            'characteristics' => 'Nuevo texto de características',
        ]);

        $this->assertSame($initial + 1, (int) $profile->fresh()->revision);
    }

    public function test_guardar_sin_cambios_no_incrementa_revision(): void
    {
        $seed = $this->seedFullTeacher();
        $profile = $seed['profile'];
        $initial = (int) $profile->revision;

        app(UpdateGroupProfile::class)->execute($seed['user'], $profile, [
            'characteristics' => $profile->characteristics,
            'student_count' => $profile->student_count,
        ]);

        $this->assertSame($initial, (int) $profile->fresh()->revision);
    }

    public function test_valores_invalidos_de_duracion_son_rechazados(): void
    {
        $seed = $this->seedFullTeacher();

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        app(UpdateGroupProfile::class)->execute($seed['user'], $seed['profile'], [
            'session_minutes' => 5,
        ]);
    }

    public function test_valores_invalidos_de_conteo_son_rechazados(): void
    {
        $seed = $this->seedFullTeacher();

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        app(UpdateGroupProfile::class)->execute($seed['user'], $seed['profile'], [
            'student_count' => 999,
        ]);
    }

    public function test_check_de_bd_bloquea_session_minutes_fuera_de_rango(): void
    {
        $seed = $this->seedFullTeacher();
        $this->expectException(QueryException::class);
        \Illuminate\Support\Facades\DB::table('group_profiles')->where('id', $seed['profile']->id)->update(['session_minutes' => 5]);
    }

    public function test_acceso_cruzado_a_perfil_es_rechazado(): void
    {
        $a = $this->customer();
        $seedB = $this->seedFullTeacher();

        $this->expectException(\Illuminate\Auth\Access\AuthorizationException::class);
        app(UpdateGroupProfile::class)->execute($a, $seedB['profile'], ['characteristics' => 'intento']);
    }

    public function test_perfil_sufficiente_expone_estado_correcto(): void
    {
        $seed = $this->seedFullTeacher();
        $this->assertTrue($seed['profile']->fresh()->isSufficient());

        $empty = GroupProfile::factory()->empty()->make();
        $this->assertFalse($empty->isSufficient());
    }
}
