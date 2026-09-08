<?php

namespace Database\Factories;

use App\Enums\PlanningRequestStatus;
use App\Models\PlanningRequest;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * NOTE: PlanningRequestFactory NO provee defaults para `owner_id`,
 * `group_id`, `curriculum_version_id` ni `grade_id` — el caller debe armar
 * un escenario coherente (owner del group === owner_id; version del group
 * === curriculum_version_id; grade pertenece a la version). Usar
 * `PedagogyTestCase::seedFullTeacher()` para obtenerlo.
 *
 * @extends Factory<PlanningRequest>
 */
class PlanningRequestFactory extends Factory
{
    protected $model = PlanningRequest::class;

    public function definition(): array
    {
        $start = now()->addDays(3)->toDateString();
        return [
            'project' => 'Proyecto ficticio ' . fake()->unique()->bothify('PR-####'),
            'topic' => 'Tema de prueba: fracciones y decimales.',
            'starts_on' => $start,
            'ends_on' => now()->addDays(9)->toDateString(),
            'period_label' => 'Semana ' . fake()->numberBetween(1, 40),
            'book_pages' => 'Libro ejemplo, pp. 12-18.',
            'required_activities' => 'Actividad inicial de diagnóstico.',
            'special_events' => null,
            'comments' => null,
            'pedagogical_notes' => 'Datos ficticios, sin validez curricular.',
            'suggested_initial_assessment' => 'Sondeo oral breve.',
            'requested_assessment' => null,
            'status' => PlanningRequestStatus::BORRADOR->value,
            'creation_mode' => null,
            'selection_revision' => 0,
            'input_revision' => 0,
        ];
    }

    public function confirmed(): static
    {
        return $this->state(fn () => [
            'status' => PlanningRequestStatus::ESPERANDO_PAGO->value,
            'curriculum_confirmed_at' => now(),
        ]);
    }
}
