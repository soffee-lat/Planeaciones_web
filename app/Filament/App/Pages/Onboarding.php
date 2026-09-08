<?php
namespace App\Filament\App\Pages;
use App\Actions\Identity\CompleteOnboarding;
use Filament\Pages\Page;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Auth;
class Onboarding extends Page {
    protected static ?string $title = 'Bienvenida';
    protected static ?string $navigationLabel = 'Mi cuenta';
    protected string $view = 'filament.app.pages.onboarding';
    public string $name = '';
    public function mount(): void { $this->name = Auth::user()->name; }
    public function save(): void {
        app(CompleteOnboarding::class)->execute(Auth::user(), Auth::user(), ['name' => $this->name]);
        Notification::make()->title('Tu cuenta está preparada')->success()->send();
        $this->redirect('/app');
    }
}

