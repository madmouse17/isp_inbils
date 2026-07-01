<?php

namespace App\Services\Core;

use App\Events\CompanyCreated;
use App\Models\Core\Company;
use Database\Seeders\CompanySeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class SetupWizardService
{
    public function create(array $data): Company
    {
        return DB::transaction(function () use ($data): Company {
            $user = Auth::user();
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
                'settings' => $this->settings($data),
                'is_active' => true,
            ]));

            if ($logo instanceof UploadedFile) {
                $path = $logo->storeAs('companies/'.$company->id, 'logo.'.$logo->extension(), 'public');
                $company->forceFill(['logo' => $path])->saveQuietly();
            }

            $user->assignRole('admin');
            $user->forceFill([
                'name' => $data['admin_name'] ?? $user->name,
                'company_id' => $company->id,
            ])->save();

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

    private function settings(array $data): array
    {
        return [
            'currency_symbol' => match ($data['currency']) {
                'USD' => '$',
                'SGD' => 'S$',
                'EUR' => '€',
                default => 'Rp',
            },
            'currency_position' => 'before',
            'date_format' => $data['date_format'],
            'datetime_format' => $data['datetime_format'],
            'tax_enabled' => true,
            'tax_ppn_rate' => 11,
            'spk_auto_invoice' => false,
            'spk_stock_reservation_mode' => 'reserve_on_assign',
            'ticket_default_sla_hours' => 24,
            'ticket_sla_mode' => 'calendar',
            'billing_default_due_days' => 30,
            'invoice_prefix' => 'INV',
            'spk_prefix' => 'SPK',
            'ticket_prefix' => 'TKT',
        ];
    }
}
