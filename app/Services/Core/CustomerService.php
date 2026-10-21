<?php

namespace App\Services\Core;

use App\Models\Core\Customer;
use App\Models\Role;
use App\Models\User;
use App\Notifications\CustomerAccountCreated;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

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
                'password' => Str::password(32),
                'is_active' => $customer->is_active,
            ]);
            $user->isCustomerAccount = true;
            $user->forceFill([
                'company_id' => CompanyService::currentId(),
                'email_verified_at' => now(),
            ])->save();
            app(CompanyMembershipService::class)->grant($user, CompanyService::current() ?? abort(403), true);
            // The copy of the Customer template, found by its key: it survives a rename and cannot be deleted.
            $user->assignRole(Role::forTemplate('customer') ?? throw ValidationException::withMessages([
                'email' => 'Peran akun pelanggan (templat Pelanggan) tidak ada di perusahaan ini.',
            ]));
            $customer->forceFill(['user_id' => $user->id])->save();
            if ($user->is_active) {
                DB::afterCommit(fn () => self::sendAccessLink($user));
            }

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

    /** Queues the set-password mail, plus the WhatsApp notice for a consenting customer; the random initial password is never disclosed. */
    public static function sendAccessLink(User $user): void
    {
        $user->notify(new CustomerAccountCreated((int) $user->company_id));
    }
}
