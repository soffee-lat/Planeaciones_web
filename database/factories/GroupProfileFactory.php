<?php

namespace Database\Factories;

use App\Models\GroupProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GroupProfile>
 */
class GroupProfileFactory extends Factory
{
    protected $model = GroupProfile::class;

    public function definition(): array
    {
        return [
            // group_id must be provided by the caller (1:1 relation).
            'revision' => 0,
            'preferred_format_id' => null,
            'student_count' => 25,
            'general_level' => 'medio',
            'characteristics' => 'Grupo participativo con interés en lectura.',
            'difficulties' => 'Atención dispersa a media mañana.',
            'educational_needs' => 'Refuerzo en comprensión lectora.',
            'session_minutes' => 50,
            'available_materials' => 'Libros, cuaderno, colores.',
            'teaching_preferences' => 'Actividades colaborativas.',
            'preferred_activities' => 'Juegos de mesa educativos.',
            'restrictions' => null,
            'management_observations' => null,
            'required_structure' => 'Inicio, desarrollo, cierre.',
            'preferred_assessment_tools' => 'Rúbrica y lista de cotejo.',
            'additional_notes' => null,
        ];
    }

    public function empty(): static
    {
        return $this->state(fn () => [
            'student_count' => null,
            'general_level' => null,
            'characteristics' => null,
            'session_minutes' => null,
        ]);
    }
}
