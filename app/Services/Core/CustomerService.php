<?php

namespace App\Services\Core;

use App\Models\Core\Customer;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CustomerService
{
    /** @param array<string, mixed> $data */
    public static function createWithUser(array $data): Customer
    {
        return DB::transaction(function () use ($data) {
            $customer = Customer::query()->create($data);

            $user = new User([
                'name' => $customer->contact_person ?: $customer->name,
                'email' => $customer->email,
                'password' => (string) $customer->phone,
                'is_active' => $customer->is_active,
            ]);
            $user->forceFill([
                'company_id' => CompanyService::currentId(),
                'email_verified_at' => now(),
            ])->save();
            $user->assignRole('customer');

            return $customer;
        });
    }
}
