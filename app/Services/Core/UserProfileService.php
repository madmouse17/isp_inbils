<?php

namespace App\Services\Core;

use App\Http\Middleware\EnsureCustomerAccess;
use App\Models\Core\EmployeeProfile;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class UserProfileService
{
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
