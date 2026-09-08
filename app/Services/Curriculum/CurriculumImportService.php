<?php

namespace App\Services\Curriculum;

use App\Enums\RoleCode;
use App\Models\ArticulatingAxis;
use App\Models\CurricularContent;
use App\Models\Curriculum;
use App\Models\CurriculumVersion;
use App\Models\EducationalPhase;
use App\Models\FormativeField;
use App\Models\Grade;
use App\Models\Pda;
use App\Models\User;
use App\Support\Curriculum\ImportReport;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Importa un catálogo curricular como borrador (nunca publica).
 * Contrato: schema JSON documentado en CURRICULUM.md, schema_version = 1.
 */
class CurriculumImportService
{
    public const SUPPORTED_SCHEMA_VERSION = 1;

    private const MAX_STRING = 1024;
    private const MAX_TEXT = 20000;

    /**
     * @param array<string,mixed> $payload  Estructura ya decodificada del archivo.
     * @param User $actor                    Debe tener rol Administrator activo.
     * @param bool $dryRun                   Si true: valida, resuelve y reporta sin escribir.
     * @param ?string $fileHash              SHA-256 del archivo original, para auditoría.
     */
    public function import(array $payload, User $actor, bool $dryRun, ?string $fileHash = null): ImportReport
    {
        $report = new ImportReport();
        $report->dryRun = $dryRun;
        $report->fileHash = $fileHash;

        // --- Autorización explícita a nivel de servicio.
        if (! $actor->hasRole(RoleCode::Administrator) || $actor->status !== 'active') {
            $report->addError('ACTOR_NOT_AUTHORIZED', 'El actor no es administrador activo.');
            return $report;
        }

        // --- Fase 1: validación estructural del payload.
        $this->validateSchema($payload, $report);
        if ($report->hasErrors()) {
            return $report;
        }

        // Después de validateSchema los tipos están garantizados.
        $report->curriculumCode = $payload['curriculum']['code'];
        $report->versionNumber = (int) $payload['version']['number'];

        // --- Fase 2: validación referencial (dentro del archivo).
        $this->validateReferences($payload, $report);
        if ($report->hasErrors()) {
            return $report;
        }

        $report->counts = [
            'curricula' => 1,
            'versions' => 1,
            'phases' => count($payload['educational_phases']),
            'grades' => count($payload['grades']),
            'fields' => count($payload['formative_fields']),
            'contents' => count($payload['curricular_contents']),
            'pdas' => count($payload['pdas']),
            'axes' => count($payload['articulating_axes']),
        ];

        // --- Fase 3: escrituras (o simulación) en transacción.
        try {
            DB::transaction(function () use ($payload, $actor, $dryRun, $report): void {
                $this->executeWithinTransaction($payload, $actor, $dryRun, $report);
                if ($report->hasErrors() || $dryRun) {
                    // Aborta la transacción sin dejar rastros.
                    throw new class extends RuntimeException {};
                }
            });
            $report->success = true;
        } catch (Throwable $e) {
            // Excepción anónima interna = rollback intencional (dry-run o errores).
            if (! ($e instanceof RuntimeException && $e->getMessage() === '')) {
                $report->addError('IMPORT_TRANSACTION_FAILED', $e->getMessage());
            }
            $report->success = false;
            $report->draftId = null;
        }

        return $report;
    }

    // ------------------------------------------------------------------
    // Validación estructural (schema)
    // ------------------------------------------------------------------

    private function validateSchema(array $payload, ImportReport $report): void
    {
        // schema_version
        if (($payload['schema_version'] ?? null) !== self::SUPPORTED_SCHEMA_VERSION) {
            $report->addError(
                'SCHEMA_VERSION_UNSUPPORTED',
                'Solo se acepta schema_version=' . self::SUPPORTED_SCHEMA_VERSION . '.',
                '/schema_version'
            );
            return;
        }

        // curriculum
        $curriculum = $payload['curriculum'] ?? null;
        if (! is_array($curriculum)) {
            $report->addError('MISSING_FIELD', 'Falta el objeto "curriculum".', '/curriculum');
            return;
        }
        $this->requireString($curriculum, 'code', '/curriculum', $report, min: 1, max: 64);
        $this->requireString($curriculum, 'name', '/curriculum', $report, min: 1, max: self::MAX_STRING);
        $this->optionalString($curriculum, 'country_code', '/curriculum', $report, max: 8);
        $this->optionalString($curriculum, 'educational_level', '/curriculum', $report, max: 64);
        $this->optionalString($curriculum, 'description', '/curriculum', $report, max: self::MAX_TEXT);

        // version
        $version = $payload['version'] ?? null;
        if (! is_array($version)) {
            $report->addError('MISSING_FIELD', 'Falta el objeto "version".', '/version');
            return;
        }
        if (! isset($version['number']) || ! is_int($version['number']) || $version['number'] < 1) {
            $report->addError('INVALID_FIELD', 'version.number debe ser entero >= 1.', '/version/number');
        }
        $this->requireString($version, 'label', '/version', $report, min: 1, max: 255);
        // source_reference puede ser string o array (metadata). Se serializa al persistir.
        if (isset($version['source_reference'])) {
            if (! is_string($version['source_reference']) && ! is_array($version['source_reference'])) {
                $report->addError('INVALID_FIELD', 'version.source_reference debe ser string u objeto.', '/version/source_reference');
            }
        }
        $this->optionalDate($version, 'effective_from', '/version', $report);
        $this->optionalDate($version, 'effective_until', '/version', $report);
        if (isset($version['effective_from'], $version['effective_until'])
            && is_string($version['effective_from']) && is_string($version['effective_until'])
            && strcmp($version['effective_until'], $version['effective_from']) < 0) {
            $report->addError('INVALID_FIELD', 'effective_until debe ser >= effective_from.', '/version');
        }

        // Arrays obligatorios no vacíos.
        foreach ([
            'educational_phases',
            'grades',
            'formative_fields',
            'curricular_contents',
            'pdas',
            'articulating_axes',
        ] as $key) {
            if (! isset($payload[$key]) || ! is_array($payload[$key]) || $payload[$key] === []) {
                $report->addError('MISSING_FIELD', "Falta el arreglo no vacío \"{$key}\".", "/{$key}");
            }
        }
        if ($report->hasErrors()) {
            return;
        }

        // Elementos individuales.
        $this->validateCollectionSimple($payload['educational_phases'], '/educational_phases', ['name'], $report);
        $this->validateCollectionSimple($payload['formative_fields'], '/formative_fields', ['name'], $report);
        $this->validateCollectionSimple($payload['articulating_axes'], '/articulating_axes', ['name'], $report);

        foreach ($payload['grades'] as $i => $row) {
            $loc = "/grades/{$i}";
            $this->requireString($row, 'code', $loc, $report, min: 1, max: 64);
            $this->requireString($row, 'name', $loc, $report, min: 1, max: self::MAX_STRING);
            $this->requireString($row, 'phase_code', $loc, $report, min: 1, max: 64);
            if (! isset($row['ordinal']) || ! is_int($row['ordinal']) || $row['ordinal'] < 1) {
                $report->addError('INVALID_FIELD', 'ordinal debe ser entero >= 1.', "{$loc}/ordinal");
            }
        }

        foreach ($payload['curricular_contents'] as $i => $row) {
            $loc = "/curricular_contents/{$i}";
            $this->requireString($row, 'code', $loc, $report, min: 1, max: 64);
            $this->requireString($row, 'title', $loc, $report, min: 1, max: self::MAX_STRING);
            $this->requireString($row, 'full_text', $loc, $report, min: 1, max: self::MAX_TEXT);
            $this->requireString($row, 'phase_code', $loc, $report, min: 1, max: 64);
            $this->requireString($row, 'field_code', $loc, $report, min: 1, max: 64);
            $this->optionalString($row, 'source_locator', $loc, $report, max: 255);
        }

        foreach ($payload['pdas'] as $i => $row) {
            $loc = "/pdas/{$i}";
            $this->requireString($row, 'code', $loc, $report, min: 1, max: 64);
            $this->requireString($row, 'full_text', $loc, $report, min: 1, max: self::MAX_TEXT);
            $this->requireString($row, 'content_code', $loc, $report, min: 1, max: 64);
            $this->requireString($row, 'grade_code', $loc, $report, min: 1, max: 64);
            $this->optionalString($row, 'source_locator', $loc, $report, max: 255);
        }
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @param string[] $requiredExtra
     */
    private function validateCollectionSimple(array $rows, string $base, array $requiredExtra, ImportReport $report): void
    {
        foreach ($rows as $i => $row) {
            $loc = "{$base}/{$i}";
            $this->requireString($row, 'code', $loc, $report, min: 1, max: 64);
            foreach ($requiredExtra as $f) {
                $this->requireString($row, $f, $loc, $report, min: 1, max: self::MAX_STRING);
            }
            $this->optionalString($row, 'description', $loc, $report, max: self::MAX_TEXT);
        }
    }

    private function requireString(array $row, string $key, string $loc, ImportReport $report, int $min = 0, int $max = 65535): void
    {
        if (! array_key_exists($key, $row)) {
            $report->addError('MISSING_FIELD', "Falta \"{$key}\".", "{$loc}/{$key}");
            return;
        }
        $value = $row[$key];
        if (! is_string($value)) {
            $report->addError('INVALID_FIELD', "\"{$key}\" debe ser cadena.", "{$loc}/{$key}");
            return;
        }
        $trimmed = trim($value);
        if ($trimmed !== $value) {
            $report->addError('INVALID_FIELD', "\"{$key}\" no puede tener espacios exteriores.", "{$loc}/{$key}");
        }
        $len = mb_strlen($value);
        if ($len < $min) {
            $report->addError('INVALID_FIELD', "\"{$key}\" no puede estar vacío.", "{$loc}/{$key}");
        }
        if ($len > $max) {
            $report->addError('INVALID_FIELD', "\"{$key}\" excede el límite de {$max} caracteres.", "{$loc}/{$key}");
        }
    }

    private function optionalString(array $row, string $key, string $loc, ImportReport $report, int $max = 65535): void
    {
        if (! array_key_exists($key, $row) || $row[$key] === null) {
            return;
        }
        if (! is_string($row[$key])) {
            $report->addError('INVALID_FIELD', "\"{$key}\" debe ser cadena o null.", "{$loc}/{$key}");
            return;
        }
        if (mb_strlen($row[$key]) > $max) {
            $report->addError('INVALID_FIELD', "\"{$key}\" excede {$max} caracteres.", "{$loc}/{$key}");
        }
    }

    private function optionalDate(array $row, string $key, string $loc, ImportReport $report): void
    {
        if (! array_key_exists($key, $row) || $row[$key] === null) {
            return;
        }
        if (! is_string($row[$key]) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $row[$key])) {
            $report->addError('INVALID_FIELD', "\"{$key}\" debe tener formato YYYY-MM-DD o ser null.", "{$loc}/{$key}");
        }
    }

    // ------------------------------------------------------------------
    // Validación referencial dentro del archivo
    // ------------------------------------------------------------------

    private function validateReferences(array $payload, ImportReport $report): void
    {
        $phaseCodes = $this->collectCodes($payload['educational_phases'], '/educational_phases', $report);
        $gradeCodes = $this->collectCodes($payload['grades'], '/grades', $report);
        $fieldCodes = $this->collectCodes($payload['formative_fields'], '/formative_fields', $report);
        $contentCodes = $this->collectCodes($payload['curricular_contents'], '/curricular_contents', $report);
        $this->collectCodes($payload['pdas'], '/pdas', $report);
        $this->collectCodes($payload['articulating_axes'], '/articulating_axes', $report);

        // grade.phase_code debe existir
        $gradePhaseByCode = [];
        foreach ($payload['grades'] as $i => $g) {
            $loc = "/grades/{$i}";
            $pc = $g['phase_code'] ?? null;
            if (! is_string($pc) || ! isset($phaseCodes[$pc])) {
                $report->addError('REFERENCE_NOT_FOUND', "phase_code \"{$pc}\" no existe.", "{$loc}/phase_code");
                continue;
            }
            $gradePhaseByCode[$g['code']] = $pc;
        }

        // content.phase_code, field_code deben existir
        $contentPhaseByCode = [];
        foreach ($payload['curricular_contents'] as $i => $c) {
            $loc = "/curricular_contents/{$i}";
            $pc = $c['phase_code'] ?? null;
            $fc = $c['field_code'] ?? null;
            if (! is_string($pc) || ! isset($phaseCodes[$pc])) {
                $report->addError('REFERENCE_NOT_FOUND', "phase_code \"{$pc}\" no existe.", "{$loc}/phase_code");
            } else {
                $contentPhaseByCode[$c['code']] = $pc;
            }
            if (! is_string($fc) || ! isset($fieldCodes[$fc])) {
                $report->addError('REFERENCE_NOT_FOUND', "field_code \"{$fc}\" no existe.", "{$loc}/field_code");
            }
        }

        // pda.content_code y grade_code deben existir; grade.phase debe coincidir con content.phase
        foreach ($payload['pdas'] as $i => $p) {
            $loc = "/pdas/{$i}";
            $cc = $p['content_code'] ?? null;
            $gc = $p['grade_code'] ?? null;
            if (! is_string($cc) || ! isset($contentCodes[$cc])) {
                $report->addError('REFERENCE_NOT_FOUND', "content_code \"{$cc}\" no existe.", "{$loc}/content_code");
                continue;
            }
            if (! is_string($gc) || ! isset($gradeCodes[$gc])) {
                $report->addError('REFERENCE_NOT_FOUND', "grade_code \"{$gc}\" no existe.", "{$loc}/grade_code");
                continue;
            }
            $contentPhase = $contentPhaseByCode[$cc] ?? null;
            $gradePhase = $gradePhaseByCode[$gc] ?? null;
            if ($contentPhase !== null && $gradePhase !== null && $contentPhase !== $gradePhase) {
                $report->addError(
                    'PDA_GRADE_PHASE_MISMATCH',
                    "PDA \"{$p['code']}\": la fase del grado \"{$gc}\" ({$gradePhase}) no coincide con la del contenido \"{$cc}\" ({$contentPhase}).",
                    $loc
                );
            }
        }

        // Cada contenido debe tener al menos un PDA (regla de publicación).
        $contentPdaCount = [];
        foreach ($payload['pdas'] as $p) {
            $cc = $p['content_code'] ?? null;
            if (is_string($cc)) {
                $contentPdaCount[$cc] = ($contentPdaCount[$cc] ?? 0) + 1;
            }
        }
        foreach (array_keys($contentCodes) as $code) {
            if (($contentPdaCount[$code] ?? 0) === 0) {
                $report->warnings[] = "Contenido \"{$code}\" no tiene PDA. La publicación posterior fallará hasta agregar al menos uno.";
            }
        }
    }

    /**
     * Devuelve mapa code=>true. Reporta duplicados dentro de la colección.
     */
    private function collectCodes(array $rows, string $base, ImportReport $report): array
    {
        $seen = [];
        foreach ($rows as $i => $row) {
            $code = $row['code'] ?? null;
            if (! is_string($code) || $code === '') {
                continue; // ya reportado por validateSchema
            }
            if (isset($seen[$code])) {
                $report->addError('DUPLICATE_CODE', "Código \"{$code}\" duplicado en {$base}.", "{$base}/{$i}/code");
            }
            $seen[$code] = true;
        }
        return $seen;
    }

    // ------------------------------------------------------------------
    // Fase 3 — DB
    // ------------------------------------------------------------------

    private function executeWithinTransaction(array $payload, User $actor, bool $dryRun, ImportReport $report): void
    {
        // Localizar/crear currículo. No modificamos metadata si existe.
        $curriculum = Curriculum::query()
            ->where('code', $payload['curriculum']['code'])
            ->lockForUpdate()
            ->first();
        if (! $curriculum) {
            $curriculum = Curriculum::create([
                'code' => $payload['curriculum']['code'],
                'name' => $payload['curriculum']['name'],
                'country_code' => $payload['curriculum']['country_code'] ?? null,
                'educational_level' => $payload['curriculum']['educational_level'] ?? null,
                'description' => $payload['curriculum']['description'] ?? null,
            ]);
        }

        // Rechazar si la versión ya existe.
        $existing = CurriculumVersion::query()
            ->where('curriculum_id', $curriculum->id)
            ->where('number', $payload['version']['number'])
            ->lockForUpdate()
            ->first();
        if ($existing) {
            $code = $existing->isPublished() ? 'VERSION_PUBLISHED' : 'VERSION_EXISTS';
            $report->addError(
                $code,
                "El currículo \"{$curriculum->code}\" ya tiene una versión #{$existing->number} ("
                    . ($existing->isPublished() ? 'publicada' : 'borrador')
                    . '). La reimportación no fusiona ni sobrescribe.',
                '/version/number'
            );
            return;
        }

        $sourceRef = $payload['version']['source_reference'] ?? null;
        if (is_array($sourceRef)) {
            $sourceRef = json_encode($sourceRef, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        $version = CurriculumVersion::create([
            'curriculum_id' => $curriculum->id,
            'number' => $payload['version']['number'],
            'label' => $payload['version']['label'],
            'source_reference' => $sourceRef,
            'effective_from' => $payload['version']['effective_from'] ?? null,
            'effective_until' => $payload['version']['effective_until'] ?? null,
        ]);

        // Insertar en orden de dependencias.
        $phaseIdByCode = [];
        foreach ($payload['educational_phases'] as $i => $row) {
            $phase = EducationalPhase::create([
                'curriculum_version_id' => $version->id,
                'code' => $row['code'],
                'name' => $row['name'],
                'description' => $row['description'] ?? null,
                'sort_order' => $row['sort_order'] ?? $i,
            ]);
            $phaseIdByCode[$row['code']] = $phase->id;
        }

        $fieldIdByCode = [];
        foreach ($payload['formative_fields'] as $i => $row) {
            $field = FormativeField::create([
                'curriculum_version_id' => $version->id,
                'code' => $row['code'],
                'name' => $row['name'],
                'description' => $row['description'] ?? null,
                'sort_order' => $row['sort_order'] ?? $i,
            ]);
            $fieldIdByCode[$row['code']] = $field->id;
        }

        $gradeIdByCode = [];
        foreach ($payload['grades'] as $i => $row) {
            $grade = Grade::create([
                'curriculum_version_id' => $version->id,
                'educational_phase_id' => $phaseIdByCode[$row['phase_code']],
                'code' => $row['code'],
                'name' => $row['name'],
                'ordinal' => $row['ordinal'],
                'sort_order' => $row['sort_order'] ?? $i,
            ]);
            $gradeIdByCode[$row['code']] = $grade->id;
        }

        $contentIdByCode = [];
        foreach ($payload['curricular_contents'] as $i => $row) {
            $content = CurricularContent::create([
                'curriculum_version_id' => $version->id,
                'educational_phase_id' => $phaseIdByCode[$row['phase_code']],
                'formative_field_id' => $fieldIdByCode[$row['field_code']],
                'code' => $row['code'],
                'title' => $row['title'],
                'full_text' => $row['full_text'],
                'source_locator' => $row['source_locator'] ?? null,
                'sort_order' => $row['sort_order'] ?? $i,
            ]);
            $contentIdByCode[$row['code']] = $content->id;
        }

        foreach ($payload['pdas'] as $i => $row) {
            Pda::create([
                'curriculum_version_id' => $version->id,
                'curricular_content_id' => $contentIdByCode[$row['content_code']],
                'grade_id' => $gradeIdByCode[$row['grade_code']],
                'code' => $row['code'],
                'full_text' => $row['full_text'],
                'source_locator' => $row['source_locator'] ?? null,
                'sort_order' => $row['sort_order'] ?? $i,
            ]);
        }

        foreach ($payload['articulating_axes'] as $i => $row) {
            ArticulatingAxis::create([
                'curriculum_version_id' => $version->id,
                'code' => $row['code'],
                'name' => $row['name'],
                'description' => $row['description'] ?? null,
                'sort_order' => $row['sort_order'] ?? $i,
            ]);
        }

        $report->draftId = $version->id;

        // Auditoría mínima: log estructurado con datos no sensibles.
        \Illuminate\Support\Facades\Log::info('curriculum.import', [
            'actor_id' => $actor->id,
            'curriculum_code' => $curriculum->code,
            'version_number' => $version->number,
            'draft_id' => $version->id,
            'file_hash' => $report->fileHash,
            'counts' => $report->counts,
            'dry_run' => $dryRun,
        ]);
    }
}
