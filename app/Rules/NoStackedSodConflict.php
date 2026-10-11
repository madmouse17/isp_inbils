<?php

namespace App\Rules;

use App\Models\User;
use App\Support\SodConflicts;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Spatie\Permission\Models\Role;

/** Rejects a role set whose merged permissions break the SoD conflict set (manager + staff stacking) unless the actor is an administrator. */
class NoStackedSodConflict implements ValidationRule
{
    public function __construct(private readonly ?User $actor) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($this->actor?->hasRole('admin')) {
            return;
        }

        // The per-item `string` and `exists` rules report malformed or unknown names; here they are only skipped.
        $names = array_values(array_filter((array) $value, 'is_string'));
        if ($names === []) {
            return;
        }

        $permissions = Role::query()
            ->whereIn('name', $names)
            ->with('permissions:id,name')
            ->get()
            ->flatMap(fn (Role $role) => $role->permissions->pluck('name'))
            ->unique()
            ->values()
            ->all();

        foreach (SodConflicts::violations($permissions) as $violation) {
            $fail($violation);
        }
    }
}
