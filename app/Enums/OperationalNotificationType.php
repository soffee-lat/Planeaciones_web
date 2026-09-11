<?php

namespace App\Enums;

enum OperationalNotificationType: string
{
    case PlanningDelivered = 'planning.delivered';
    case ReviewAssigned = 'review.assigned';
    case SubscriptionRenewalUpcoming = 'subscription.renewal_upcoming';
}
