<?php

namespace App\Rules;

use App\Models\Core\EmployeeProfile;
use App\Services\Core\CompanyService;
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
            ->where('company_id', CompanyService::currentId())
            ->where('user_id', $value)
            ->where('status', 'active')
            ->whereHas('user', function ($query) {
                $query->where('company_id', CompanyService::currentId())
                    ->where('is_active', true)
                    ->where(function ($q) {
                        $q->whereHas('permissions', fn ($pq) => $pq->whereIn('name', ['spk.start', 'spk.submit']))
                            ->orWhereHas('roles.permissions', fn ($pq) => $pq->whereIn('name', ['spk.start', 'spk.submit']));
                    });
            })
            ->exists();

        if (! $exists) {
            $fail('Selected technician must be an active employee with SPK lifecycle capability.');
        }
    }
}
