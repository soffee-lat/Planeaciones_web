<?php

namespace App\Services\Planning;

use App\Models\ArticulatingAxis;
use App\Models\CurricularContent;
use App\Models\Pda;

/**
 * Sugerencia curricular determinista (MVP · sin IA).
 *
 * Estrategia: normaliza texto de entrada (project + topic + book_pages +
 * special_events + comments), tokeniza, quita stopwords en español, y busca
 * coincidencias parciales sobre `CurricularContent.title`, `CurricularContent.full_text`
 * y `Pda.full_text` dentro de la versión curricular y grado del grupo.
 *
 * Ponderación por token coincidente:
 *   +3 en título de contenido
 *   +2 en full_text de contenido
 *   +1 en full_text de PDA (con bonus si su contenido ya calificó)
 *   +1 en nombre/descripción de eje articulador
 *
 * Ordena por (score desc, id asc) para resultado determinista. Nunca cruza
 * versiones ni grados: los IDs devueltos siempre pertenecen al catálogo
 * publicado del grupo.
 *
 * @see docs/CURRICULUM.md — sección "Propuesta automática — MVP acotado"
 */
class CurriculumSuggestionService
{
    public const STRATEGY_VERSION = 'deterministic_v1';

    private const STOPWORDS = [
        'a','al','ante','bajo','con','contra','de','del','desde','durante','en','entre',
        'hacia','hasta','mediante','para','por','segun','sin','sobre','tras','y','o','u',
        'e','ni','pero','sino','que','como','cuando','donde','porque','mas','si','no',
        'el','la','los','las','un','una','unos','unas','lo','le','les','se','su','sus',
        'me','mi','mis','te','tu','tus','nos','os','este','esta','esto','estos','estas',
        'ese','esa','eso','esos','esas','aquel','aquella','ser','es','son','fue','sea',
        'muy','mas','tambien','tan','tanto','tanta','todos','todas','todo','toda','otro',
        'otra','otros','otras','ya','solo','asi','ahi','aqui','alla','cada','sus','ha',
        'han','hay','habia','haber','del','al','ni',
    ];

    /**
     * @param  array{project?:?string,topic?:?string,book_pages?:?string,special_events?:?string,comments?:?string,required_activities?:?string}  $input
     * @return array{
     *   strategy_version:string,
     *   tokens:array<int,string>,
     *   content_ids:array<int,int>,
     *   pda_ids:array<int,int>,
     *   axis_ids:array<int,int>,
     *   formative_field_ids:array<int,int>,
     *   has_strong_match:bool,
     *   reasons:array<int,string>,
     * }
     */
    public function suggest(
        int $curriculumVersionId,
        int $gradeId,
        array $input,
        int $maxContents = 6,
        int $maxPdasPerContent = 3,
        int $maxAxes = 3,
        ?string $fieldCode = null,
    ): array
    {
        $rawText = trim(collect([
            $input['project'] ?? null,
            $input['topic'] ?? null,
            $input['book_pages'] ?? null,
            $input['required_activities'] ?? null,
            $input['special_events'] ?? null,
            $input['comments'] ?? null,
        ])->filter()->implode(' '));

        $tokens = $this->tokenize($rawText);

        $empty = [
            'strategy_version' => self::STRATEGY_VERSION,
            'tokens' => $tokens,
            'content_ids' => [],
            'pda_ids' => [],
            'axis_ids' => [],
            'formative_field_ids' => [],
            'has_strong_match' => false,
            'reasons' => [],
        ];

        if ($tokens === []) {
            return $empty;
        }

        // Cargar candidatos SIEMPRE filtrados por versión y grado.
        $contents = CurricularContent::query()
            ->where('curriculum_version_id', $curriculumVersionId)
            ->whereHas('pdas', fn ($q) => $q->where('grade_id', $gradeId))
            ->when($fieldCode !== null && trim($fieldCode) !== '', function ($query) use ($fieldCode) {
                $query->whereHas('formativeField', fn ($field) => $field->where('code', trim($fieldCode)));
            })
            ->orderBy('id')
            ->get(['id', 'formative_field_id', 'title', 'full_text']);

        if ($contents->isEmpty()) {
            return $empty;
        }

        $contentScores = [];
        $contentReasons = [];
        foreach ($contents as $c) {
            $normTitle = $this->normalize((string) $c->title);
            $normText = $this->normalize((string) $c->full_text);
            $score = 0;
            $matched = [];
            foreach ($tokens as $t) {
                if ($t === '' || mb_strlen($t) < 3) {
                    continue;
                }
                if (mb_strpos($normTitle, $t) !== false) {
                    $score += 3;
                    $matched[] = $t;
                }
                if (mb_strpos($normText, $t) !== false) {
                    $score += 2;
                    $matched[] = $t;
                }
            }
            if ($score > 0) {
                $contentScores[$c->id] = $score;
                $contentReasons[$c->id] = 'Coincide en: ' . implode(', ', array_unique($matched));
            }
        }

        if ($contentScores === []) {
            return $empty;
        }

        arsort($contentScores);
        $topContentIds = array_slice(array_keys($contentScores), 0, $maxContents, true);

        // PDAs del grado dentro de contenidos elegidos.
        $pdas = Pda::query()
            ->where('curriculum_version_id', $curriculumVersionId)
            ->where('grade_id', $gradeId)
            ->whereIn('curricular_content_id', $topContentIds)
            ->orderBy('curricular_content_id')
            ->orderBy('id')
            ->get(['id', 'curricular_content_id', 'full_text']);

        $pdasByContent = $pdas->groupBy('curricular_content_id');
        $pdaIds = [];
        foreach ($topContentIds as $cid) {
            /** @var \Illuminate\Support\Collection<int,\App\Models\Pda> $group */
            $group = $pdasByContent->get($cid) ?? collect();
            $scored = [];
            foreach ($group as $p) {
                $normPda = $this->normalize((string) $p->full_text);
                $s = 1; // baseline por pertenecer a contenido calificado
                foreach ($tokens as $t) {
                    if (mb_strlen($t) < 3) {
                        continue;
                    }
                    if (mb_strpos($normPda, $t) !== false) {
                        $s += 1;
                    }
                }
                $scored[$p->id] = $s;
            }
            arsort($scored);
            foreach (array_slice(array_keys($scored), 0, $maxPdasPerContent) as $pid) {
                $pdaIds[] = $pid;
            }
        }

        // Ejes: puntuar por coincidencia en name/description; si nada, no forzar.
        $axes = ArticulatingAxis::query()
            ->where('curriculum_version_id', $curriculumVersionId)
            ->orderBy('id')
            ->get(['id', 'name', 'description']);

        $axisScores = [];
        foreach ($axes as $a) {
            $norm = $this->normalize(((string) $a->name) . ' ' . ((string) $a->description));
            $s = 0;
            foreach ($tokens as $t) {
                if (mb_strlen($t) < 3) {
                    continue;
                }
                if (mb_strpos($norm, $t) !== false) {
                    $s += 1;
                }
            }
            if ($s > 0) {
                $axisScores[$a->id] = $s;
            }
        }
        arsort($axisScores);
        $axisIds = array_slice(array_keys($axisScores), 0, $maxAxes);

        // Campos formativos derivados de contenidos sugeridos.
        $fieldIds = $contents->whereIn('id', $topContentIds)->pluck('formative_field_id')->unique()->values()->all();

        return [
            'strategy_version' => self::STRATEGY_VERSION,
            'tokens' => $tokens,
            'content_ids' => array_values($topContentIds),
            'pda_ids' => array_values(array_unique($pdaIds)),
            'axis_ids' => array_values($axisIds),
            'formative_field_ids' => array_values(array_filter($fieldIds)),
            'has_strong_match' => max($contentScores) >= 3,
            'reasons' => array_intersect_key($contentReasons, array_flip($topContentIds)),
        ];
    }

    private function normalize(string $text): string
    {
        $t = mb_strtolower($text);
        // Quitar acentos.
        $t = strtr($t, [
            'á' => 'a','é' => 'e','í' => 'i','ó' => 'o','ú' => 'u','ü' => 'u','ñ' => 'n',
            'Á' => 'a','É' => 'e','Í' => 'i','Ó' => 'o','Ú' => 'u','Ü' => 'u','Ñ' => 'n',
        ]);
        // Reemplazar puntuación por espacio.
        $t = preg_replace('/[^a-z0-9\s]+/u', ' ', $t) ?? '';
        return trim((string) preg_replace('/\s+/', ' ', $t));
    }

    /** @return array<int,string> */
    private function tokenize(string $text): array
    {
        $norm = $this->normalize($text);
        if ($norm === '') {
            return [];
        }
        $parts = explode(' ', $norm);
        $out = [];
        foreach ($parts as $p) {
            if (mb_strlen($p) < 3) {
                continue;
            }
            if (in_array($p, self::STOPWORDS, true)) {
                continue;
            }
            $out[] = $p;
        }
        return array_values(array_unique($out));
    }
}
