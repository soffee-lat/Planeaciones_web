<?php

namespace App\Actions\Review;

use App\Enums\HumanReviewStatus;
use App\Models\HumanReview;
use App\Models\PlanningRequest;
use App\Models\User;

final class EscalateHumanReview
{
    public function __construct(private FlagHumanReviewForAdmin $flag) {}

    public function execute(HumanReview $review, User $actor, string $reason, ?string $correlationId = null): PlanningRequest
    {
        return $this->flag->execute($review, $actor, HumanReviewStatus::Escalated, $reason, $correlationId);
    }
}
