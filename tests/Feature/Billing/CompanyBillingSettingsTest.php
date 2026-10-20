<?php

namespace Tests\Feature\Billing;

use App\Services\Core\SetupWizardService;
use App\Support\OptimisticLock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Billing\Services\BillingService;
use Tests\TestCase;
use Tests\Traits\CreatesCompanyUser;

class CompanyBillingSettingsTest extends TestCase
{
    use CreatesCompanyUser;
    use RefreshDatabase;

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
                ->has('settings.bank_account_info')
                ->where('settings.auto_send_installation_invoice', false)
                ->where('settings.bill_installation_materials', false));
    }

    public function test_the_retired_spk_auto_invoice_key_is_no_longer_offered(): void
    {
        $user = $this->createCompanyUser();
        $user->company->update(['settings' => ['spk_auto_invoice' => true, 'tax_ppn_rate' => 11]]);
        $this->actingAs($user);

        $this->get(route('admin.company.settings.edit'))
            ->assertInertia(fn ($page) => $page->has('settings.tax_ppn_rate')->missing('settings.spk_auto_invoice'));
        $this->assertArrayNotHasKey('spk_auto_invoice', SetupWizardService::defaultSettings('IDR', 'd M Y', 'd M Y H:i'));
        $this->assertFalse(SetupWizardService::defaultSettings('IDR', 'd M Y', 'd M Y H:i')['auto_send_installation_invoice']);
        $this->assertFalse(SetupWizardService::defaultSettings('IDR', 'd M Y', 'd M Y H:i')['bill_installation_materials']);
    }

    public function test_the_installation_invoice_switches_are_saved_as_booleans(): void
    {
        $user = $this->createCompanyUser();
        $this->actingAs($user);
        $version = fn () => OptimisticLock::version($user->company->fresh());

        $this->putJson(route('admin.company.settings.update'), ['settings' => ['auto_send_installation_invoice' => 'kadang'], OptimisticLock::FIELD => $version()])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('settings.auto_send_installation_invoice');
        $this->putJson(route('admin.company.settings.update'), ['settings' => ['bill_installation_materials' => null], OptimisticLock::FIELD => $version()])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('settings.bill_installation_materials');

        $this->put(route('admin.company.settings.update'), ['settings' => ['auto_send_installation_invoice' => true, 'bill_installation_materials' => true], OptimisticLock::FIELD => $version()])
            ->assertRedirect();

        $settings = $user->company->fresh()->settings;
        $this->assertTrue($settings['auto_send_installation_invoice']);
        $this->assertTrue($settings['bill_installation_materials']);
    }
}
