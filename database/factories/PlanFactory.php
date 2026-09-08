<?php

namespace Database\Factories;

use App\Models\Plan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Plan>
 */
class PlanFactory extends Factory
{
    protected $model = Plan::class;

    public function definition(): array
    {
        static $seq = 0;
        $seq++;
        return [
            'code' => 'PLAN-DEMO-' . strtoupper(bin2hex(random_bytes(3))) . '-' . $seq,
            'name' => 'Plan DEMO ' . $seq,
            'description' => 'Datos ficticios de demostración; sin validez comercial.',
            'active' => true,
        ];
    }

    public function inactive(): self
    {
        return $this->state(fn () => ['active' => false]);
    }
}
