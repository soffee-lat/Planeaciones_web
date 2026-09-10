<?php

namespace Database\Factories;

use App\Enums\InstitutionalFormatKind;
use App\Enums\InstitutionalFormatStatus;
use App\Models\InstitutionalFormat;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<InstitutionalFormat> */
class InstitutionalFormatFactory extends Factory
{
    protected $model = InstitutionalFormat::class;

    public function definition(): array
    {
        return [
            'owner_id' => User::factory(),
            'name' => 'Formato institucional '.fake()->unique()->numerify('###'),
            'kind' => InstitutionalFormatKind::Institutional->value,
            'status' => InstitutionalFormatStatus::Configuring->value,
        ];
    }
}
