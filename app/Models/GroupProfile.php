<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GroupProfile extends Model
{
    use HasFactory;

    /**
     * Fields whose changes MUST bump `revision`. `revision` itself, timestamps,
     * PK, and FK `group_id` are excluded because they are not editorial content
     * of the profile.
     *
     * @var list<string>
     */
    public const PEDAGOGICAL_FIELDS = [
        'preferred_format_id',
        'student_count',
        'general_level',
        'characteristics',
        'difficulties',
        'educational_needs',
        'session_minutes',
        'available_materials',
        'teaching_preferences',
        'preferred_activities',
        'restrictions',
        'management_observations',
        'required_structure',
        'preferred_assessment_tools',
        'additional_notes',
    ];

    /**
     * Minimum fields required to consider the profile "sufficient" for onboarding.
     *
     * @var list<string>
     */
    public const REQUIRED_FOR_COMPLETENESS = [
        'student_count',
        'general_level',
        'session_minutes',
        'characteristics',
    ];

    protected $fillable = [
        'group_id',
        'revision',
        'preferred_format_id',
        'student_count',
        'general_level',
        'characteristics',
        'difficulties',
        'educational_needs',
        'session_minutes',
        'available_materials',
        'teaching_preferences',
        'preferred_activities',
        'restrictions',
        'management_observations',
        'required_structure',
        'preferred_assessment_tools',
        'additional_notes',
    ];

    protected $attributes = [
        'revision' => 0,
    ];

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    public function preferredFormat(): BelongsTo
    {
        return $this->belongsTo(InstitutionalFormat::class, 'preferred_format_id');
    }

    public function isSufficient(): bool
    {
        foreach (self::REQUIRED_FOR_COMPLETENESS as $field) {
            $value = $this->getAttribute($field);
            if ($value === null || $value === '' || $value === 0) {
                return false;
            }
        }

        return true;
    }
}
