<?php

namespace App\Services\Analytics;

use App\Enums\ProductEventType;
use App\Enums\RoleCode;
use App\Models\Group;
use App\Models\PlanningRequest;
use App\Models\ProductEvent;
use App\Models\User;

final class ProductEventRecorder
{
    public function record(
        User $actor,
        ProductEventType $type,
        ?PlanningRequest $request = null,
        ?Group $group = null,
        array $metadata = [],
    ): ProductEvent {
        $this->assertActor($actor);

        if ($request && (int) $request->owner_id !== (int) $actor->id) {
            throw new \RuntimeException('PRODUCT_EVENT_REQUEST_OWNER_MISMATCH');
        }
        if ($group && (int) $group->owner_id !== (int) $actor->id) {
            throw new \RuntimeException('PRODUCT_EVENT_GROUP_OWNER_MISMATCH');
        }
        if ($request && $group && (int) $request->group_id !== (int) $group->id) {
            throw new \RuntimeException('PRODUCT_EVENT_GROUP_REQUEST_MISMATCH');
        }

        $metadata = $this->sanitizeMetadata($type, $metadata);

        $groupId = $request?->group_id ?? $group?->id;
        $curriculumVersionId = $request?->curriculum_version_id ?? $group?->curriculum_version_id;
        $gradeId = $request?->grade_id ?? $group?->grade_id;

        return ProductEvent::query()->create([
            'user_id' => $actor->id,
            'planning_request_id' => $request?->id,
            'group_id' => $groupId,
            'curriculum_version_id' => $curriculumVersionId,
            'grade_id' => $gradeId,
            'event_type' => $type->value,
            'metadata' => $metadata,
            'occurred_at' => now(),
        ]);
    }

    private function assertActor(User $actor): void
    {
        if ($actor->status !== 'active' || ! $actor->hasVerifiedEmail() || ! $actor->hasRole(RoleCode::Customer)) {
            throw new \RuntimeException('PRODUCT_EVENT_ACTOR_REQUIRED');
        }
    }

    /** @return list<string> */
    private function allowedMetadata(ProductEventType $type): array
    {
        return match ($type) {
            ProductEventType::PlanningStarted => ['entry_surface', 'profile_reused', 'session_minutes_known', 'format_version_id'],
            ProductEventType::CurriculumSuggestionsShown => [
                'strategy_version', 'suggestion_fingerprint', 'content_count', 'pda_count',
                'axis_count', 'formative_field_count', 'has_strong_match',
            ],
            ProductEventType::CurriculumSuggestionAccepted,
            ProductEventType::CurriculumSuggestionRejected,
            ProductEventType::CurriculumSelectionAdded => ['entity_type', 'entity_id', 'origin', 'suggestion_fingerprint'],
            ProductEventType::CurriculumMapConfirmed => ['selection_revision', 'fingerprint', 'content_count', 'pda_count', 'axis_count'],
            ProductEventType::PlanGenerated => ['document_version_id', 'renderer'],
            ProductEventType::PlanSectionEdited,
            ProductEventType::PlanSectionRegenerated,
            ProductEventType::PlanSectionDeleted => ['section_key'],
            ProductEventType::FormatSampleGenerated => ['format_version_id'],
            ProductEventType::DocxDownloaded => ['delivery_id', 'file_id', 'format_version_id'],
            ProductEventType::PlanningCompleted => ['delivery_id', 'feedback_submitted'],
        };
    }

    private function sanitizeMetadata(ProductEventType $type, array $metadata): array
    {
        $allowed = $this->allowedMetadata($type);
        $unknown = array_values(array_diff(array_keys($metadata), $allowed));
        if ($unknown !== []) {
            throw new \RuntimeException('PRODUCT_EVENT_METADATA_NOT_ALLOWED:' . implode(',', $unknown));
        }

        foreach ($metadata as $key => $value) {
            if (! is_null($value) && ! is_scalar($value)) {
                throw new \RuntimeException('PRODUCT_EVENT_METADATA_SCALAR_REQUIRED:' . $key);
            }
            if (is_string($value) && mb_strlen($value) > 128) {
                throw new \RuntimeException('PRODUCT_EVENT_METADATA_TOO_LONG:' . $key);
            }
        }

        if (isset($metadata['entry_surface']) && ! in_array($metadata['entry_surface'], ['curricular_validation_v1', 'legacy_wizard'], true)) {
            throw new \RuntimeException('PRODUCT_EVENT_ENTRY_SURFACE_INVALID');
        }
        if (isset($metadata['entity_type']) && ! in_array($metadata['entity_type'], ['content', 'pda', 'axis', 'field'], true)) {
            throw new \RuntimeException('PRODUCT_EVENT_ENTITY_TYPE_INVALID');
        }
        if (isset($metadata['origin']) && ! in_array($metadata['origin'], ['suggested', 'catalog', 'teacher_added'], true)) {
            throw new \RuntimeException('PRODUCT_EVENT_ORIGIN_INVALID');
        }
        foreach (['fingerprint', 'suggestion_fingerprint'] as $fingerprintKey) {
            if (isset($metadata[$fingerprintKey]) && (! is_string($metadata[$fingerprintKey]) || ! preg_match('/^[0-9a-f]{64}$/', $metadata[$fingerprintKey]))) {
                throw new \RuntimeException('PRODUCT_EVENT_FINGERPRINT_INVALID:' . $fingerprintKey);
            }
        }
        if (isset($metadata['section_key']) && (! is_string($metadata['section_key']) || ! preg_match('/^[a-z0-9_.-]+$/', $metadata['section_key']))) {
            throw new \RuntimeException('PRODUCT_EVENT_SECTION_KEY_INVALID');
        }

        return $metadata;
    }
}
