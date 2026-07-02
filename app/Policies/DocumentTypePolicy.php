<?php

namespace App\Policies;

use App\Models\Core\DocumentType;
use App\Models\User;

class DocumentTypePolicy
{
    public function viewAny(User $user): bool { return $user->can('system.setting'); }
    public function view(User $user, DocumentType $dt): bool { return $user->can('system.setting'); }
    public function create(User $user): bool { return $user->can('system.setting'); }
    public function update(User $user, DocumentType $dt): bool { return $user->can('system.setting'); }
    public function delete(User $user, DocumentType $dt): bool { return $user->can('system.setting'); }
}
