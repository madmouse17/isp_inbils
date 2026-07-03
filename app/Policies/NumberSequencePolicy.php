<?php

namespace App\Policies;

use App\Models\Core\NumberSequence;
use App\Models\User;

class NumberSequencePolicy
{
    public function viewAny(User $user): bool { return $user->can('system.setting'); }
    public function view(User $user, NumberSequence $seq): bool { return $user->can('system.setting'); }
    public function create(User $user): bool { return $user->can('system.setting'); }
    public function update(User $user, NumberSequence $seq): bool { return $user->can('system.setting'); }
    public function delete(User $user, NumberSequence $seq): bool { return $user->can('system.setting'); }
}
