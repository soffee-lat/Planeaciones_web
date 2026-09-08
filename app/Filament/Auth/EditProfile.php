<?php
namespace App\Filament\Auth;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
class EditProfile extends \Filament\Auth\Pages\EditProfile {
    protected function handleRecordUpdate(Model $record, array $data): Model {
        Gate::authorize('update', $record);
        return parent::handleRecordUpdate($record, $data);
    }
}
