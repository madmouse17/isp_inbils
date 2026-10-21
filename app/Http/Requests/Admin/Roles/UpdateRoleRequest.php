<?php

namespace App\Http\Requests\Admin\Roles;

use App\Models\Role;
use App\Support\OptimisticLock;
use Illuminate\Validation\Rules\Unique;

/** Renames a role or replaces its permissions. The form sends the version it was rendered from (G8). */
class UpdateRoleRequest extends StoreRoleRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->role()) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            OptimisticLock::FIELD => OptimisticLock::rules(),
        ];
    }

    protected function uniqueName(): Unique
    {
        return parent::uniqueName()->ignore($this->role()->id);
    }

    private function role(): Role
    {
        /** @var Role */
        return $this->route('role');
    }
}
