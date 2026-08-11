<?php

namespace App\Rules;

use App\Models\Core\EmployeeProfile;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Ensure the submitted user id is an active employee with the technician role.
 * Reusable across SPK assignment and any future technician-scoped assignment.
 */
class IsActiveTechnician implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $exists = EmployeeProfile::query()
            ->where('user_id', $value)
            ->where('status', 'active')
            ->whereHas('user.roles', fn ($query) => $query->where('name', 'technician'))
            ->exists();

        if (! $exists) {
            $fail('Selected technician must be an employee with technician role.');
        }
    }
}
