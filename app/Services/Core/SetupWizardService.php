<?php

namespace App\Services\Core;

use App\Events\CompanyCreated;
use App\Models\Core\Company;
use App\Models\User;
use Database\Seeders\CompanySeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class SetupWizardService
{
    /** The Setup Wizard: the signed-in user becomes the first admin of the company they create. */
    public function create(array $data): Company
    {
        return $this->createFor(Auth::user(), $data);
    }

    /**
     * Creates a company with its first admin, in the order the wizard always had: the company without automatic events,
     * its logo, the admin role, the admin homed and made a default member, the audit rows, then the defaults seeder and
     * CompanyCreated. The admin exists and is homed before seeding, so the seeder acts as them and never falls back to
     * a system-seed user. Shared by the wizard ($admin = the signed-in user) and platform provisioning (a new user).
     */
    public function createFor(User $admin, array $data): Company
    {
        return DB::transaction(function () use ($admin, $data): Company {
            $user = $admin;
            $logo = $data['logo'] ?? null;
            unset($data['logo']);

            $company = Company::withoutEvents(fn () => Company::query()->create([
                'name' => $data['name'],
                'code' => $data['code'],
                'address' => $data['address'] ?? null,
                'phone' => $data['phone'] ?? null,
                'email' => $data['email'] ?? null,
                'website' => $data['website'] ?? null,
                'timezone' => $data['timezone'],
                'currency' => $data['currency'],
                'settings' => self::defaultSettings($data['currency'], $data['date_format'], $data['datetime_format']),
                'is_active' => true,
            ]));

            if ($logo instanceof UploadedFile) {
                CompanyLogoService::store($company, $logo);
            }

            $user->assignRole('admin');
            $user->forceFill([
                'name' => $data['admin_name'] ?? $user->name,
                'company_id' => $company->id,
            ])->save();
            app(CompanyMembershipService::class)->grant($user, $company, true);

            AuditService::log('company', 'company_created', ['company_id' => $company->id], $company);
            AuditService::log('auth', 'admin_role_assigned', ['user_id' => $user->id, 'company_id' => $company->id], $user);
            CompanyService::resetCache();
            app(CompanySeeder::class)->runFor($company);
            CompanyCreated::dispatch($company);

            return $company->refresh();
        });
    }

    public function isRequired(): bool
    {
        return Company::query()->count() === 0;
    }

    /** The operational defaults of a new company, shared by the Setup Wizard and subsidiary creation. */
    public static function defaultSettings(string $currency, string $dateFormat, string $datetimeFormat): array
    {
        return [
            'currency_symbol' => match ($currency) {
                'USD' => '$',
                'SGD' => 'S$',
                'EUR' => '€',
                default => 'Rp',
            },
            'currency_position' => 'before',
            'date_format' => $dateFormat,
            'datetime_format' => $datetimeFormat,
            'tax_enabled' => true,
            'tax_ppn_rate' => 11,
            'spk_auto_invoice' => false,
            'spk_stock_reservation_mode' => 'reserve_on_assign',
            'billing_default_due_days' => 30,
            'invoice_prefix' => 'INV',
            'spk_prefix' => 'SPK',
            'ticket_prefix' => 'TKT',
        ];
    }
}
