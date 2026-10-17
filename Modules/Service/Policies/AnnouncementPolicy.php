<?php

namespace Modules\Service\Policies;

use App\Models\User;
use Modules\Service\Models\Announcement;

class AnnouncementPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('announcement.manage');
    }

    public function view(User $user, Announcement $announcement): bool
    {
        return $user->can('announcement.manage');
    }

    public function create(User $user): bool
    {
        return $user->can('announcement.manage');
    }

    public function update(User $user, Announcement $announcement): bool
    {
        return $user->can('announcement.manage');
    }

    public function delete(User $user, Announcement $announcement): bool
    {
        return $user->can('announcement.manage');
    }
}
