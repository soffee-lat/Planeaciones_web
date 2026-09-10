<?php

namespace App\Services\Review;

use App\Data\AI\AuditFinding;
use App\Enums\AiExecutionStage;
use App\Enums\HumanReviewStatus;
use App\Exceptions\HumanReviewException;
use App\Models\AiExecution;
use App\Models\HumanReview;
use App\Support\AI\CanonicalJson;

final class HumanReviewCorrectionPolicy
{
    private const UNSAFE_FAILED_KEYS = ['grade', 'dates', 'contents', 'pda', 'fields', 'axes'];

    private const MUTABLE_SECTIONS = [
        'planning',
        'pedagogical_design',
        'sessions',
        'assessment_plan',
        'resources',
        'adaptation_notes',
    ];

    /**
     * @return array{section_keys:list<string>,findings:list<AuditFinding>,payload_hash:string,correction_round:int}
     */
    public function build(HumanReview $review): array
    {
        if ($review->status !== HumanReviewStatus::InProgress) {
            throw new HumanReviewException('HUMAN_REVIEW_CORRECTION_REVIEW_NOT_EDITABLE');
        }

        return $this->context($review, true);
    }

    /** @return array{section_keys:list<string>,findings:list<AuditFinding>,payload_hash:string,correction_round:int} */
    public function buildForTerminalReview(HumanReview $review): array
    {
        if ($review->status !== HumanReviewStatus::ChangesRequested) {
            throw new HumanReviewException('HUMAN_REVIEW_CORRECTION_SOURCE_REVIEW_INVALID');
        }

        return $this->context($review, false);
    }

    /** @return array{section_keys:list<string>,findings:list<AuditFinding>,payload_hash:string,correction_round:int} */
    private function context(HumanReview $review, bool $enforceRoundLimit): array
    {
        $review->loadMissing(['checklistVersion.items', 'responses.item']);
        $responses = $review->responses->keyBy(fn ($response) => $response->item?->key);

        $failedKeys = [];
        foreach ($review->checklistVersion->items as $item) {
            if (! $item->required) {
                continue;
            }
            $response = $responses->get($item->key);
            if (! $response || (bool) $response->passed !== true) {
                $failedKeys[] = $item->key;
            }
        }

        if ($failedKeys === []) {
            throw new HumanReviewException('HUMAN_REVIEW_CORRECTION_FAILED_ITEM_REQUIRED');
        }
        if (array_intersect(self::UNSAFE_FAILED_KEYS, $failedKeys) !== []) {
            throw new HumanReviewException('HUMAN_REVIEW_CORRECTION_SCOPE_UNSAFE');
        }

        $sectionComments = is_array($review->section_comments) ? $review->section_comments : [];
        foreach (['context', 'curricular_alignment'] as $immutable) {
            if (isset($sectionComments[$immutable]) && trim((string) $sectionComments[$immutable]) !== '') {
                throw new HumanReviewException('HUMAN_REVIEW_CORRECTION_SCOPE_UNSAFE');
            }
        }

        $sectionKeys = [];
        $findings = [];
        foreach (self::MUTABLE_SECTIONS as $section) {
            $comment = trim((string) ($sectionComments[$section] ?? ''));
            if ($comment === '') {
                continue;
            }
            $sectionKeys[] = $section;
            $findings[] = new AuditFinding(
                code: 'HUMAN_REVIEW_CORRECTION',
                severity: 'medium',
                jsonPath: '/' . $section,
                explanation: $comment,
                expectedCorrection: 'Atender la observación del revisor sin modificar secciones fuera del alcance indicado.',
            );
        }

        if ($sectionKeys === []) {
            throw new HumanReviewException('HUMAN_REVIEW_CORRECTION_SECTION_COMMENT_REQUIRED');
        }

        sort($sectionKeys, SORT_STRING);
        usort($findings, fn (AuditFinding $a, AuditFinding $b): int => strcmp($a->jsonPath, $b->jsonPath));

        if ($enforceRoundLimit) {
            $maxRounds = (int) config('ai.human_review_correction.max_rounds', 3);
            if ($maxRounds < 1 || $maxRounds > 20) {
                throw new HumanReviewException('HUMAN_REVIEW_CORRECTION_MAX_ROUNDS_INVALID');
            }
            $humanRounds = AiExecution::query()
                ->where('request_id', $review->request_id)
                ->where('stage', AiExecutionStage::Correction->value)
                ->where('input_manifest->source_kind', 'human_review')
                ->count();
            if ($humanRounds >= $maxRounds) {
                throw new HumanReviewException('HUMAN_REVIEW_CORRECTION_ROUND_LIMIT_REACHED');
            }
        }

        $correctionRound = AiExecution::query()
            ->where('request_id', $review->request_id)
            ->where('stage', AiExecutionStage::Correction->value)
            ->count() + 1;

        $failed = [];
        foreach ($failedKeys as $key) {
            $response = $responses->get($key);
            $failed[] = [
                'key' => $key,
                'comment' => $response?->comment,
            ];
        }
        usort($failed, fn (array $a, array $b): int => strcmp($a['key'], $b['key']));
        ksort($sectionComments, SORT_STRING);

        $payloadHash = CanonicalJson::hash([
            'review_id' => (int) $review->id,
            'request_id' => (int) $review->request_id,
            'assignment_id' => (int) $review->assignment_id,
            'version_id' => (int) $review->version_id,
            'checklist_version_id' => (int) $review->checklist_version_id,
            'reviewer_id' => (int) $review->reviewer_id,
            'failed_items' => $failed,
            'general_comment' => $review->general_comment,
            'section_comments' => $sectionComments,
        ]);

        return compact('sectionKeys', 'findings', 'payloadHash', 'correctionRound');
    }
}
