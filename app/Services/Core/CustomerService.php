<?php

namespace App\Services\Core;

use App\Models\Core\Customer;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class CustomerService
{
    /** @param array<string, mixed> $data */
    public static function createWithUser(array $data): Customer
    {
        return DB::transaction(function () use ($data) {
            $addresses = Arr::pull($data, 'addresses', []);
            $contacts = Arr::pull($data, 'contacts', []);
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
            $customer->forceFill(['user_id' => $user->id])->save();

            foreach ($addresses as $address) {
                $customer->addresses()->create(IndonesiaRegionService::normalizeAddress($address));
            }

            foreach ($contacts as $contact) {
                $contact['role'] = Arr::pull($contact, 'position');
                $customer->contacts()->create($contact);
            }

            return $customer;
        });
    }
}
