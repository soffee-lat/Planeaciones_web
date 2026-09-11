<?php

namespace App\Enums;

enum ProductEventType: string
{
    case PlanningStarted = 'planning_started';
    case CurriculumSuggestionsShown = 'curriculum_suggestions_shown';
    case CurriculumSuggestionAccepted = 'curriculum_suggestion_accepted';
    case CurriculumSuggestionRejected = 'curriculum_suggestion_rejected';
    case CurriculumSelectionAdded = 'curriculum_selection_added';
    case CurriculumMapConfirmed = 'curriculum_map_confirmed';
    case PlanGenerated = 'plan_generated';
    case PlanSectionEdited = 'plan_section_edited';
    case PlanSectionRegenerated = 'plan_section_regenerated';
    case PlanSectionDeleted = 'plan_section_deleted';
    case FormatSampleGenerated = 'format_sample_generated';
    case DocxDownloaded = 'docx_downloaded';
    case PlanningCompleted = 'planning_completed';
}
