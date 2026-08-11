<?php

namespace App\Rules;

use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Ensure the submitted user id is active and holds one of the given roles.
 * Reusable wherever an assignment FK needs role-scoped validation
 * (ticket handler assign, SPK technician assign, etc).
 */
class HasAnyActiveRole implements ValidationRule
{
    /** @param string[] $roles */
    public function __construct(private readonly array $roles) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $user = User::query()->find($value);

        if (! $user || ! $user->is_active || ! $user->hasAnyRole($this->roles)) {
            $fail('The selected :attribute must be an active user with an eligible role.');
        }
    }
}
