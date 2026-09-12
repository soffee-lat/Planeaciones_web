<?php

namespace App\Services\AI;

use App\Data\Planning\GeneratedPlanDraft;
use App\Exceptions\AiContractException;

class GeneratedPlanDraftValidator
{
    public const CONTRACT_VERSION = 'generated_plan_draft_v1';

    public function __construct(private JsonSchemaSubsetValidator $schemaValidator) {}

    /** @param array<string,mixed> $payload */
    public function validate(array $payload): GeneratedPlanDraft
    {
        $schema = $this->loadSchema(resource_path('schemas/ai/generated_plan_draft_v1.schema.json'));

        // `custom` se define dinámicamente por el contrato del formato de esta
        // ejecución. El contrato base sigue siendo v1; la extensión se valida
        // de forma segura y acotada para admitir listas, tablas y bloques.
        $basePayload = $payload;
        unset($basePayload['custom']);
        $this->schemaValidator->validate($basePayload, $schema);
        $this->validateCustom($payload['custom'] ?? null);
        $this->validateSessions($payload);
        $this->validateInstruments($payload);

        return new GeneratedPlanDraft($payload);
    }

    private function validateCustom(mixed $custom): void
    {
        if ($custom === null) {
            return;
        }
        if (! is_array($custom) || array_is_list($custom)) {
            throw new AiContractException('GENERATED_CUSTOM_OBJECT_REQUIRED', '$.custom');
        }

        $nodes = 0;
        foreach ($custom as $key => $value) {
            $this->assertCustomKey((string) $key, '$.custom');
            $this->validateCustomValue($value, '$.custom.' . $key, 0, $nodes);
        }
    }

    private function validateCustomValue(mixed $value, string $path, int $depth, int &$nodes): void
    {
        $nodes++;
        if ($nodes > 500) {
            throw new AiContractException('GENERATED_CUSTOM_TOO_LARGE', $path);
        }
        if ($depth > 5) {
            throw new AiContractException('GENERATED_CUSTOM_TOO_DEEP', $path);
        }

        if (is_string($value)) {
            if (trim($value) === '') {
                throw new AiContractException('GENERATED_CUSTOM_VALUE_EMPTY', $path);
            }
            return;
        }

        if (! is_array($value) || $value === []) {
            throw new AiContractException('GENERATED_CUSTOM_VALUE_INVALID', $path);
        }

        if (array_is_list($value)) {
            foreach ($value as $index => $item) {
                $this->validateCustomValue($item, $path . '[' . $index . ']', $depth + 1, $nodes);
            }
            return;
        }

        foreach ($value as $key => $item) {
            $this->assertCustomKey((string) $key, $path);
            $this->validateCustomValue($item, $path . '.' . $key, $depth + 1, $nodes);
        }
    }

    private function assertCustomKey(string $key, string $path): void
    {
        if (preg_match('/^[a-z][a-z0-9_]{1,63}$/', $key) !== 1) {
            throw new AiContractException('GENERATED_CUSTOM_KEY_INVALID', $path . '.' . $key);
        }
    }

    /** @param array<string,mixed> $payload */
    private function validateSessions(array $payload): void
    {
        $sessions = $payload['sessions'];
        $ids = [];
        $sequences = [];

        foreach ($sessions as $index => $session) {
            $id = $session['id'];
            if (isset($ids[$id])) {
                throw new AiContractException('GENERATED_SESSION_ID_DUPLICATE', '$.sessions[' . $index . '].id', $id);
            }
            $ids[$id] = true;
            $sequence = (int) $session['sequence'];
            $sequences[] = $sequence;
            if ($sequence !== $index + 1 || (int) substr($id, 1) !== $sequence) {
                throw new AiContractException('GENERATED_SESSION_SEQUENCE_INVALID', '$.sessions[' . $index . ']', $id);
            }

            $momentTypes = array_column($session['moments'], 'type');
            if ($momentTypes !== ['inicio', 'desarrollo', 'cierre']) {
                throw new AiContractException('GENERATED_SESSION_MOMENTS_INVALID', '$.sessions[' . $index . '].moments');
            }

            $minutes = array_sum(array_map(fn (array $moment) => (int) $moment['minutes'], $session['moments']));
            if ($minutes !== (int) $session['estimated_minutes']) {
                throw new AiContractException('GENERATED_SESSION_MINUTES_MISMATCH', '$.sessions[' . $index . '].estimated_minutes');
            }
        }

        $expected = range(1, count($sessions));
        sort($sequences);
        if ($sequences !== $expected) {
            throw new AiContractException('GENERATED_SESSION_SEQUENCE_INVALID', '$.sessions');
        }
    }

    /** @param array<string,mixed> $payload */
    private function validateInstruments(array $payload): void
    {
        $sessionIds = array_fill_keys(array_column($payload['sessions'], 'id'), true);
        $instrumentIds = [];

        foreach ($payload['assessment_plan']['instruments'] as $index => $instrument) {
            $id = $instrument['id'];
            if (isset($instrumentIds[$id])) {
                throw new AiContractException('GENERATED_INSTRUMENT_ID_DUPLICATE', '$.assessment_plan.instruments[' . $index . '].id', $id);
            }
            $instrumentIds[$id] = true;

            foreach ($instrument['applies_to_sessions'] as $sessionId) {
                if (! isset($sessionIds[$sessionId])) {
                    throw new AiContractException('GENERATED_INSTRUMENT_SESSION_NOT_FOUND', '$.assessment_plan.instruments[' . $index . '].applies_to_sessions', $sessionId);
                }
            }
        }

        foreach ($payload['sessions'] as $index => $session) {
            foreach ($session['formative_assessment']['instrument_ids'] as $instrumentId) {
                if (! isset($instrumentIds[$instrumentId])) {
                    throw new AiContractException('GENERATED_SESSION_INSTRUMENT_NOT_FOUND', '$.sessions[' . $index . '].formative_assessment.instrument_ids', $instrumentId);
                }
            }
        }
    }

    /** @return array<string,mixed> */
    private function loadSchema(string $path): array
    {
        $decoded = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($decoded)) {
            throw new AiContractException('AI_SCHEMA_INVALID', '$', basename($path));
        }

        return $decoded;
    }
}
