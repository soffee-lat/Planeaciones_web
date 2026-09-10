<?php

namespace Database\Factories;

use App\Models\FormatVersion;
use App\Models\InstitutionalFormat;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<FormatVersion> */
class FormatVersionFactory extends Factory
{
    protected $model = FormatVersion::class;

    public function definition(): array
    {
        return [
            'format_id' => InstitutionalFormat::factory(),
            'number' => 1,
            'source_file_id' => null,
            'mapping' => ['schema_version' => 1, 'sections' => ['identity', 'sessions']],
            'schema_version' => 1,
            'renderer' => 'institutional-v1',
            'validation_report' => ['status' => 'pending'],
            'approved_by' => null,
            'published_at' => null,
        ];
    }
}
