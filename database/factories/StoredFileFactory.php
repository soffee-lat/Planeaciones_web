<?php

namespace Database\Factories;

use App\Enums\FileCategory;
use App\Enums\FileScanStatus;
use App\Models\StoredFile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<StoredFile> */
class StoredFileFactory extends Factory
{
    protected $model = StoredFile::class;

    public function definition(): array
    {
        $name = fake()->uuid().'.bin';
        return [
            'owner_id' => User::factory(),
            'request_id' => null,
            'category' => FileCategory::Other->value,
            'disk' => 'private',
            'path' => 'tests/files/'.$name,
            'original_name' => $name,
            'detected_mime' => 'application/octet-stream',
            'size_bytes' => 16,
            'sha256' => hash('sha256', $name),
            'scan_status' => FileScanStatus::Pending->value,
            'uploaded_by' => null,
        ];
    }
}
