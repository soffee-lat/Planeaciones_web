<?php

namespace Tests\Unit;

use App\Services\Curriculum\EditorialMarkerDetector;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class EditorialMarkerDetectorTest extends TestCase
{
    #[DataProvider('allowedTexts')]
    public function test_it_allows_words_that_only_contain_demo_as_a_substring(string $text): void
    {
        $this->assertFalse(EditorialMarkerDetector::contains($text));
    }

    #[DataProvider('blockedTexts')]
    public function test_it_rejects_explicit_editorial_markers(string $text): void
    {
        $this->assertTrue(EditorialMarkerDetector::contains($text));
    }

    public static function allowedTexts(): array
    {
        return [
            ['Democracia como forma de vida'],
            ['Participación democrática en la comunidad'],
            ['Análisis demográfico de la población'],
            ['Una demostración mediante ejemplos'],
        ];
    }

    public static function blockedTexts(): array
    {
        return [
            ['Catálogo DEMO'],
            ['DEMO-1'],
            ['demo_catalog'],
            ['Contenido ficticio'],
            ['Situaciones ficticias'],
            ['Sin validez curricular'],
            ['Contenido de ejemplo'],
            ['PDA de ejemplo'],
            ['__PENDING_EDITORIAL__'],
        ];
    }
}
