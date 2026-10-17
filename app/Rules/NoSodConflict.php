<?php

namespace App\Rules;

use App\Support\SodConflicts;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Rejects a role permission set that breaks the SoD conflict set. Only the seeded `admin` role is exempt (break-glass),
 * so the platform panel, the one place role definitions are written, passes `exempt: true` for that role alone.
 */
class NoSodConflict implements ValidationRule
{
    public function __construct(private readonly bool $exempt = false) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($this->exempt) {
            return;
        }

        // The per-item `string` rule reports malformed entries; here they are only skipped.
        $names = array_values(array_filter((array) $value, 'is_string'));

        foreach (SodConflicts::violations($names) as $violation) {
            $fail($violation);
        }
    }
}
