<?php

namespace App\Policies;

use App\Models\CurriculumVersion;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

abstract class CurriculumElementPolicy
{
    use AuthorizesCurriculumTree;

    public function viewAny(User $user): bool
    {
        return $this->canReadCatalog($user);
    }

    public function view(User $user, Model $element): bool
    {
        return $this->canReadCatalog($user);
    }

    public function create(User $user): bool
    {
        return $this->isAdmin($user);
    }

    public function update(User $user, Model $element): bool
    {
        return $this->isAdmin($user) && $this->isDraft($element);
    }

    public function delete(User $user, Model $element): bool
    {
        return $this->isAdmin($user) && $this->isDraft($element);
    }

    protected function isDraft(Model $element): bool
    {
        $version = CurriculumVersion::query()->find($element->curriculum_version_id);

        return $version !== null && $version->isDraft();
    }
}
