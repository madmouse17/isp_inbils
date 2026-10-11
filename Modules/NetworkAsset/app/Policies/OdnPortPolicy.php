<?php

namespace Modules\NetworkAsset\Policies;

use App\Models\User;
use Modules\NetworkAsset\Models\OdnPort;

class OdnPortPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('odn.view');
    }

    public function create(User $user): bool
    {
        return $user->can('odn.manage');
    }

    public function delete(User $user, OdnPort $port): bool
    {
        return $user->can('odn.manage');
    }

    /** Setting or releasing the drop a customer uses is an assignment, not register management. */
    public function assignDrop(User $user, OdnPort $port): bool
    {
        return $user->can('odn.assign');
    }
}
