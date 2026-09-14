<?php

namespace App\Models;

use App\Enums\PlanningRequestStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PlanningRequest extends Model
{
    use HasFactory;

    /**
     * Campos editables durante BORRADOR cuyo cambio real incrementa
     * `input_revision`. selecciones curriculares (pivotes) se manejan por
     * separado y también incrementan la revisión vía SyncPlanningRequestSelections.
     *
     * @var list<string>
     */
    public const TRACKED_INPUT_FIELDS = [
        'creation_mode',
        'format_version_id',
        'starts_on',
        'ends_on',
        'period_label',
        'project',
        'topic',
        'book_pages',
        'required_activities',
        'special_events',
        'comments',
        'pedagogical_notes',
        'suggested_initial_assessment',
        'requested_assessment',
    ];

    /**
     * Campos que alimentan directamente la propuesta curricular determinista.
     * Si alguno cambia, una confirmación previa del mapa deja de ser vigente.
     *
     * @var list<string>
     */
    public const CURRICULUM_MAP_INPUT_FIELDS = [
        'project',
        'topic',
        'book_pages',
        'required_activities',
        'special_events',
        'comments',
    ];

    protected $fillable = [
        'owner_id',
        'group_id',
        'curriculum_version_id',
        'grade_id',
        'creation_mode',
        'selection_revision',
        'curriculum_confirmed_at',
        'curriculum_selection_fingerprint',
        'starts_on',
        'ends_on',
        'period_label',
        'project',
        'topic',
        'book_pages',
        'required_activities',
        'special_events',
        'comments',
        'pedagogical_notes',
        'suggested_initial_assessment',
        'requested_assessment',
        'status',
        'due_at',
        'input_revision',
        'input_snapshot',
        'current_version_id',
        'format_version_id',
        'lock_version',
    ];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'curriculum_confirmed_at' => 'datetime',
            'due_at' => 'datetime',
            'input_snapshot' => 'array',
            'status' => PlanningRequestStatus::class,
            'calculation_snapshot' => 'array',
            'commercial_authorized_at' => 'datetime',
            'human_review_required_snapshot' => 'boolean',
            'planning_days' => 'integer',
            'planning_units' => 'integer',
            'correction_limit_snapshot' => 'integer',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function subscriptionPeriod(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPeriod::class);
    }

    public function planVersion(): BelongsTo
    {
        return $this->belongsTo(PlanVersion::class);
    }

    public function segments(): HasMany
    {
        return $this->hasMany(PlanningRequestSegment::class)->orderBy('sequence');
    }

    public function usageReservations(): HasMany
    {
        return $this->hasMany(UsageReservation::class);
    }

    public function curriculumVersion(): BelongsTo
    {
        return $this->belongsTo(CurriculumVersion::class);
    }

    public function grade(): BelongsTo
    {
        return $this->belongsTo(Grade::class);
    }

    public function contents(): BelongsToMany
    {
        return $this->belongsToMany(
            CurricularContent::class,
            'request_curricular_contents',
            'request_id',
            'curricular_content_id',
        )->withPivot(['curriculum_version_id']);
    }

    public function pdas(): BelongsToMany
    {
        return $this->belongsToMany(
            Pda::class,
            'request_pdas',
            'request_id',
            'pda_id',
        )->withPivot(['curriculum_version_id', 'grade_id']);
    }

    public function articulatingAxes(): BelongsToMany
    {
        return $this->belongsToMany(
            ArticulatingAxis::class,
            'request_articulating_axes',
            'request_id',
            'articulating_axis_id',
        )->withPivot(['curriculum_version_id']);
    }

    public function inputVersions(): HasMany
    {
        return $this->hasMany(RequestInputVersion::class, 'request_id');
    }

    public function currentInputVersion(): BelongsTo
    {
        return $this->belongsTo(RequestInputVersion::class, 'current_version_id');
    }

    public function stateEvents(): HasMany
    {
        return $this->hasMany(RequestStateEvent::class, 'request_id');
    }

    public function productEvents(): HasMany
    {
        return $this->hasMany(ProductEvent::class, 'planning_request_id');
    }

    public function feedback(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(PlanningFeedback::class, 'planning_request_id');
    }

    public function aiExecutions(): HasMany
    {
        return $this->hasMany(AiExecution::class, 'request_id');
    }

    public function formatVersion(): BelongsTo
    {
        return $this->belongsTo(FormatVersion::class, 'format_version_id');
    }

    public function document(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(Document::class, 'request_id');
    }

    public function documentRenderRuns(): HasMany
    {
        return $this->hasMany(DocumentRenderRun::class, 'request_id');
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(PlanningDelivery::class, 'request_id')->orderByDesc('delivered_at');
    }

    public function correctionRequests(): HasMany
    {
        return $this->hasMany(CorrectionRequest::class, 'request_id')->orderByDesc('requested_at');
    }

    public function blocks(): HasMany
    {
        return $this->hasMany(RequestBlock::class, 'request_id');
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(Approval::class, 'request_id');
    }

    public function reviewAssignments(): HasMany
    {
        return $this->hasMany(ReviewerAssignment::class, 'request_id');
    }

    public function humanReviews(): HasMany
    {
        return $this->hasMany(HumanReview::class, 'request_id');
    }

    public function isDraft(): bool
    {
        return $this->status === PlanningRequestStatus::BORRADOR;
    }

    public function isConfirmed(): bool
    {
        return $this->status !== PlanningRequestStatus::BORRADOR
            && $this->status !== PlanningRequestStatus::CANCELADA;
    }

    public function hasConfirmedCurriculumMap(): bool
    {
        return $this->curriculum_confirmed_at !== null
            && is_string($this->curriculum_selection_fingerprint)
            && preg_match('/^[0-9a-f]{64}$/', $this->curriculum_selection_fingerprint) === 1;
    }

    public function scopeOwnedBy(Builder $query, int $userId): Builder
    {
        return $query->where('owner_id', $userId);
    }

    public function scopeDrafts(Builder $query): Builder
    {
        return $query->where('status', PlanningRequestStatus::BORRADOR->value);
    }
}
