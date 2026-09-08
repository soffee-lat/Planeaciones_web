<?php

namespace App\Actions\Curriculum;

use App\Models\CurriculumVersion;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class PublishCurriculumVersion
{
    /** Marcador editorial canónico permitido en borradores, prohibido al publicar. */
    public const PENDING_EDITORIAL_MARKER = '__PENDING_EDITORIAL__';

    /**
     * Publish a draft CurriculumVersion after validating structural invariants.
     * Throws RuntimeException with a semantic code when publication is not allowed.
     */
    public function __invoke(CurriculumVersion $version, User $actor): CurriculumVersion
    {
        return DB::transaction(function () use ($version, $actor): CurriculumVersion {
            // Lock the curriculum root row so concurrent publish/edit is serialized.
            DB::table('curricula')->where('id', $version->curriculum_id)->lockForUpdate()->first();

            $fresh = CurriculumVersion::query()->whereKey($version->id)->lockForUpdate()->first();
            if (! $fresh) {
                throw new RuntimeException('CURRICULUM_VERSION_NOT_FOUND');
            }
            if ($fresh->published_at !== null) {
                throw new RuntimeException('CURRICULUM_VERSION_ALREADY_PUBLISHED');
            }

            $this->validateTree($fresh);

            $checksum = $this->computeChecksum($fresh);

            $fresh->fill([
                'published_at' => now(),
                'published_by' => $actor->id,
                'checksum' => $checksum,
            ])->save();

            return $fresh->fresh();
        });
    }

    protected function validateTree(CurriculumVersion $version): void
    {
        $vid = $version->id;

        $counts = [
            'phases' => DB::table('educational_phases')->where('curriculum_version_id', $vid)->count(),
            'grades' => DB::table('grades')->where('curriculum_version_id', $vid)->count(),
            'fields' => DB::table('formative_fields')->where('curriculum_version_id', $vid)->count(),
            'contents' => DB::table('curricular_contents')->where('curriculum_version_id', $vid)->count(),
            'pdas' => DB::table('pdas')->where('curriculum_version_id', $vid)->count(),
        ];

        foreach ($counts as $entity => $count) {
            if ($count === 0) {
                throw new RuntimeException('CURRICULUM_VERSION_EMPTY_' . strtoupper($entity));
            }
        }

        // Every content must have at least one PDA (por CURRICULUM.md, todo
        // contenido ofrecido para un grado debe tener al menos un PDA aplicable).
        $contentWithoutPda = DB::table('curricular_contents as c')
            ->leftJoin('pdas as p', function ($join) {
                $join->on('p.curricular_content_id', '=', 'c.id')
                    ->on('p.curriculum_version_id', '=', 'c.curriculum_version_id');
            })
            ->where('c.curriculum_version_id', $vid)
            ->whereNull('p.id')
            ->pluck('c.code');
        if ($contentWithoutPda->isNotEmpty()) {
            throw new RuntimeException('CURRICULUM_CONTENT_WITHOUT_PDA:' . $contentWithoutPda->implode(','));
        }

        // Every PDA's grade must belong to the same phase as its content.
        $misaligned = DB::table('pdas as p')
            ->join('curricular_contents as c', function ($join) {
                $join->on('c.id', '=', 'p.curricular_content_id')
                    ->on('c.curriculum_version_id', '=', 'p.curriculum_version_id');
            })
            ->join('grades as g', function ($join) {
                $join->on('g.id', '=', 'p.grade_id')
                    ->on('g.curriculum_version_id', '=', 'p.curriculum_version_id');
            })
            ->where('p.curriculum_version_id', $vid)
            ->whereColumn('c.educational_phase_id', '<>', 'g.educational_phase_id')
            ->pluck('p.code');

        // Textos editoriales pendientes: no se permite publicar mientras un
        // contenido o PDA conserve el marcador editorial canónico.
        $marker = self::PENDING_EDITORIAL_MARKER;
        $pendingContents = DB::table('curricular_contents')
            ->where('curriculum_version_id', $vid)
            ->where(function ($q) use ($marker) {
                $q->where('title', 'like', '%' . $marker . '%')
                    ->orWhere('full_text', 'like', '%' . $marker . '%');
            })
            ->pluck('code')
            ->map(fn ($c) => 'content:' . $c);
        $pendingPdas = DB::table('pdas')
            ->where('curriculum_version_id', $vid)
            ->where('full_text', 'like', '%' . $marker . '%')
            ->pluck('code')
            ->map(fn ($c) => 'pda:' . $c);
        $pending = $pendingContents->concat($pendingPdas);
        if ($pending->isNotEmpty()) {
            throw new RuntimeException('CURRICULUM_EDITORIAL_CONTENT_INCOMPLETE:' . $pending->implode(','));
        }
        if ($misaligned->isNotEmpty()) {
            throw new RuntimeException('PDA_GRADE_PHASE_MISMATCH:' . $misaligned->implode(','));
        }
    }

    protected function computeChecksum(CurriculumVersion $version): string
    {
        $vid = $version->id;
        $payload = [
            'curriculum_id' => $version->curriculum_id,
            'number' => $version->number,
            'phases' => DB::table('educational_phases')->where('curriculum_version_id', $vid)->orderBy('id')
                ->get(['id', 'code', 'name', 'description', 'sort_order'])->toArray(),
            'fields' => DB::table('formative_fields')->where('curriculum_version_id', $vid)->orderBy('id')
                ->get(['id', 'code', 'name', 'description', 'sort_order'])->toArray(),
            'grades' => DB::table('grades')->where('curriculum_version_id', $vid)->orderBy('id')
                ->get(['id', 'educational_phase_id', 'code', 'name', 'ordinal', 'sort_order'])->toArray(),
            'contents' => DB::table('curricular_contents')->where('curriculum_version_id', $vid)->orderBy('id')
                ->get(['id', 'educational_phase_id', 'formative_field_id', 'code', 'title', 'full_text', 'source_locator', 'sort_order'])->toArray(),
            'pdas' => DB::table('pdas')->where('curriculum_version_id', $vid)->orderBy('id')
                ->get(['id', 'curricular_content_id', 'grade_id', 'code', 'full_text', 'source_locator', 'sort_order'])->toArray(),
            'axes' => DB::table('articulating_axes')->where('curriculum_version_id', $vid)->orderBy('id')
                ->get(['id', 'code', 'name', 'description', 'sort_order'])->toArray(),
        ];

        return hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
