<?php

namespace Database\Factories;

use App\Enums\SchoolType;
use App\Models\School;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<School>
 */
class SchoolFactory extends Factory
{
    protected $model = School::class;

    public function definition(): array
    {
        return [
            'owner_id' => User::factory(),
            'name' => 'Escuela ' . fake()->unique()->numerify('#####'),
            'school_type' => fake()->randomElement([SchoolType::Public->value, SchoolType::Private->value]),
            'state' => fake()->randomElement(['Ciudad de México', 'Jalisco', 'Nuevo León', 'Puebla']),
            'municipality' => fake()->city(),
            'notes' => null,
        ];
    }
}
