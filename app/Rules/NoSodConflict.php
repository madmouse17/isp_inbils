<?php

namespace App\Rules;

use App\Models\User;
use App\Support\SodConflicts;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** Rejects a role permission set that breaks the SoD conflict set unless the actor is an administrator. */
class NoSodConflict implements ValidationRule
{
    public function __construct(private readonly ?User $actor) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($this->actor?->hasRole('admin')) {
            return;
        }

        // The per-item `string` rule reports malformed entries; here they are only skipped.
        $names = array_values(array_filter((array) $value, 'is_string'));

        foreach (SodConflicts::violations($names) as $violation) {
            $fail($violation);
        }
    }
}
