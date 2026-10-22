<?php

namespace App\Services\Core;

use App\Http\Middleware\EnsureCustomerAccess;
use App\Models\Core\EmployeeProfile;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class UserProfileService
{
    /**
     * Whether the employee block of a new staff login holds anything: an empty block creates no profile.
     *
     * @param  array<string, mixed>|null  $data
     */
    public static function hasEmployeeData(?array $data): bool
    {
        return $data !== null && collect($data)->contains(fn (mixed $value): bool => filled($value));
    }

    /**
     * Creates the optional employee record selected with a new staff login.
     *
     * @param  array{phone?: ?string, hire_date?: ?string, status?: ?string, notes?: ?string}|null  $data
     */
    public static function createEmployee(User $user, ?array $data): ?EmployeeProfile
    {
        if (! self::hasEmployeeData($data)) {
            return null;
        }

        $companyId = CompanyService::idFor($user) ?? abort(403);

        return EmployeeProfile::create([
            'company_id' => $companyId,
            'user_id' => $user->id,
            'employee_number' => NumberSequenceService::generate('employee', $companyId),
            'phone' => $data['phone'] ?? null,
            'hire_date' => $data['hire_date'] ?? null,
            'status' => $data['status'] ?? 'active',
            'notes' => $data['notes'] ?? null,
        ]);
    }

    /**
     * What an app user edits about themselves: the name on the login and the phone of the person behind it, which
     * is the customer's contact number in the customer app and the employee's in the technician app. Billing
     * and employment details stay with staff.
     *
     * @param  array{name?: string, phone?: ?string}  $data
     */
    public static function update(User $user, string $app, array $data): void
    {
        DB::transaction(function () use ($user, $app, $data): void {
            if (array_key_exists('name', $data)) {
                $user->update(['name' => $data['name']]);
            }

            if (array_key_exists('phone', $data)) {
                $person = $app === ApiTokenService::CUSTOMER
                    ? EnsureCustomerAccess::customerFor($user)
                    : EmployeeProfile::forCompany(CompanyService::idFor($user))->where('user_id', $user->id)->first();

                $person?->update(['phone' => $data['phone']]);
            }
        });
    }
}
