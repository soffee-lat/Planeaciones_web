<?php
namespace App\Providers\Filament;
use Filament\Panel;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
class AdminPanelProvider extends BasePanelProvider {
    public function panel(Panel $panel): Panel {
        return $this->base($panel)->id('admin')->path('admin')
            ->brandName('Planeaciones · Administración')
            ->colors(['primary' => Color::Amber])
            ->pages([
                \App\Filament\Admin\Pages\Dashboard::class,
                \App\Filament\Admin\Pages\AiOperations::class,
            ])
            ->renderHook(
                PanelsRenderHook::BODY_END,
                fn () => view('filament.admin.partials.manual-ai-alerts'),
            )
            ->resources([
                \App\Filament\Resources\Users\UserResource::class,
                \App\Filament\Resources\PlanningRequests\PlanningRequestResource::class,
                \App\Filament\Resources\Curricula\CurriculumResource::class,
                \App\Filament\Resources\CurriculumVersions\CurriculumVersionResource::class,
                \App\Filament\Resources\Plans\PlanResource::class,
                \App\Filament\Resources\PlanVersions\PlanVersionResource::class,
                \App\Filament\Resources\Subscriptions\SubscriptionResource::class,
                \App\Filament\Resources\SubscriptionPeriods\SubscriptionPeriodResource::class,
                \App\Filament\Resources\UsageReservations\UsageReservationResource::class,
                \App\Filament\Resources\Orders\OrderResource::class,
                \App\Filament\Resources\Payments\PaymentResource::class,
                \App\Filament\Resources\Refunds\RefundResource::class,
                \App\Filament\Resources\PaymentEvents\PaymentEventResource::class,
                \App\Filament\Resources\PromptTemplates\PromptTemplateResource::class,
                \App\Filament\Resources\PromptVersions\PromptVersionResource::class,
            ]);
    }
}
