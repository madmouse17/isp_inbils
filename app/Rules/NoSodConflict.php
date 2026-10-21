<?php

namespace App\Rules;

use App\Support\SodConflicts;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Rejects a role permission set that breaks the SoD conflict set. A set that holds roles.manage is the company
 * administrator's break-glass role: the requests that save roles (tenant, template, platform repair) pass `exempt: true`
 * for it and for nothing else.
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
