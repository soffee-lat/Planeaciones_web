<?php

namespace App\Actions\Documents;

use App\Enums\InstitutionalFormatKind;
use App\Enums\InstitutionalFormatStatus;
use App\Models\FormatVersion;
use App\Models\InstitutionalFormat;
use Illuminate\Support\Facades\DB;

final class EnsureStandardFormat
{
    /** @return array{format:InstitutionalFormat,version:FormatVersion} */
    public function execute(): array
    {
        return DB::transaction(function (): array {
            $format = InstitutionalFormat::query()
                ->whereNull('owner_id')
                ->where('kind', InstitutionalFormatKind::Standard->value)
                ->lockForUpdate()
                ->first();

            if (! $format) {
                $format = InstitutionalFormat::query()->create([
                    'owner_id' => null,
                    'name' => 'Formato estándar',
                    'kind' => InstitutionalFormatKind::Standard->value,
                    'status' => InstitutionalFormatStatus::Ready->value,
                ]);
            } elseif ($format->status !== InstitutionalFormatStatus::Ready) {
                $format->forceFill(['status' => InstitutionalFormatStatus::Ready->value])->save();
            }

            $version = FormatVersion::query()
                ->where('format_id', $format->id)
                ->where('number', 1)
                ->first();

            if (! $version) {
                $version = FormatVersion::query()->create([
                    'format_id' => $format->id,
                    'number' => 1,
                    'source_file_id' => null,
                    'mapping' => self::mapping(),
                    'schema_version' => 1,
                    'renderer' => 'standard-v1',
                    'validation_report' => [
                        'status' => 'approved',
                        'checks' => ['canonical_plan_v1', 'standard_sections_v1'],
                    ],
                    'approved_by' => null,
                    'published_at' => now(),
                ]);
            }

            return ['format' => $format->fresh(), 'version' => $version->fresh()];
        }, attempts: 3);
    }

    /** @return array<string,mixed> */
    public static function mapping(): array
    {
        return [
            'schema_version' => 1,
            'source_contract' => 'canonical_plan_v1',
            'layout' => 'standard_v1',
            'sections' => [
                'identity', 'context', 'curricular_alignment', 'pedagogical_design',
                'sessions', 'assessment_plan', 'resources', 'adaptation_notes',
            ],
        ];
    }
}
