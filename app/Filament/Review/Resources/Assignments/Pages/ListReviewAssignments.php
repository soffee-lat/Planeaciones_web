<?php

namespace App\Filament\Review\Resources\Assignments\Pages;

use App\Filament\Review\Resources\Assignments\ReviewAssignmentResource;
use Filament\Resources\Pages\ListRecords;

class ListReviewAssignments extends ListRecords
{
    protected static string $resource = ReviewAssignmentResource::class;
}
