<?php

namespace Tests\Feature\Billing;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Billing\Services\BillingService;
use Tests\TestCase;
use Tests\Traits\CreatesCompanyUser;

class CompanyBillingSettingsTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCompanyUser;

    public function test_tax_rate_prefers_company_setting_over_global(): void
    {
        $user = $this->createCompanyUser();
        $company = $user->company;

        // global seeded default_tax_ppn_rate = 11
        $this->assertSame(11.0, BillingService::taxRateFor($company->id));

        $company->update(['settings' => array_merge($company->settings ?? [], ['tax_ppn_rate' => 0])]);
        $this->assertSame(0.0, BillingService::taxRateFor($company->id));
    }

    public function test_due_days_defaults_to_14_and_reads_company_setting(): void
    {
        $user = $this->createCompanyUser();
        $company = $user->company;

        $this->assertSame(14, BillingService::dueDaysFor($company->id));

        $company->update(['settings' => array_merge($company->settings ?? [], ['invoice_due_days' => 30])]);
        $this->assertSame(30, BillingService::dueDaysFor($company->id));
    }

    public function test_settings_page_shows_billing_keys(): void
    {
        $user = $this->createCompanyUser();
        $this->actingAs($user);

        $this->get(route('admin.company.settings.edit'))
            ->assertInertia(fn ($page) => $page
                ->has('settings.tax_ppn_rate')
                ->has('settings.invoice_due_days')
                ->has('settings.bank_account_info'));
    }
}
