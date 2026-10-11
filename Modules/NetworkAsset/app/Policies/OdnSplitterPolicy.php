<?php

namespace Modules\NetworkAsset\Policies;

use App\Models\User;
use Modules\NetworkAsset\Models\OdnSplitter;

class OdnSplitterPolicy
{
    public function create(User $user): bool
    {
        return $user->can('odn.manage');
    }

    public function update(User $user, OdnSplitter $splitter): bool
    {
        return $user->can('odn.manage');
    }

    public function delete(User $user, OdnSplitter $splitter): bool
    {
        return $user->can('odn.manage');
    }
}
