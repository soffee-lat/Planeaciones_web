<?php

namespace App\Services\Curriculum;

use Illuminate\Support\Str;

/**
 * Detects explicit editorial/demo markers without treating ordinary words
 * such as "democracia" or "demográfico" as DEMO content.
 */
final class EditorialMarkerDetector
{
    /** @var list<string> */
    private const STANDALONE_WORDS = [
        'demo',
        'ficticio',
        'ficticia',
        'ficticios',
        'ficticias',
    ];

    /** @var list<string> */
    private const BLOCKED_PHRASES = [
        'sin validez curricular',
        'contenido de ejemplo',
        'pda de ejemplo',
    ];

    /** @var list<string> */
    private const TECHNICAL_MARKERS = [
        '__pending_editorial__',
    ];

    public static function contains(mixed $value): bool
    {
        if ($value === null) {
            return false;
        }

        if (is_array($value) || is_object($value)) {
            $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        if (! is_scalar($value)) {
            return false;
        }

        $normalized = mb_strtolower(Str::squish((string) $value));

        foreach ([...self::BLOCKED_PHRASES, ...self::TECHNICAL_MARKERS] as $marker) {
            if (str_contains($normalized, $marker)) {
                return true;
            }
        }

        foreach (self::STANDALONE_WORDS as $word) {
            $pattern = '/(?<![\p{L}\p{N}])' . preg_quote($word, '/') . '(?![\p{L}\p{N}])/u';
            if (preg_match($pattern, $normalized) === 1) {
                return true;
            }
        }

        return false;
    }
}
