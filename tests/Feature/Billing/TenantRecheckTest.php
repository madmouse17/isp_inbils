<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Models\Core\Company;
use App\Models\Core\Customer;
use App\Models\Core\CustomerAddress;
use App\Models\Core\ServiceSubscription;
use App\Models\User;
use App\Services\Core\CompanyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Services\BillingService;
use Modules\Service\Models\BandwidthProfile;
use Modules\Service\Models\ServicePackage;
use Modules\Service\Models\SLATier;
use Modules\Service\Models\SpeedProfile;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;
use Tests\Traits\CreatesCompanyUser;

class TenantRecheckTest extends TestCase
{
    use CreatesCompanyUser;
    use RefreshDatabase;

    // ── cancel() tenant recheck ─────────────────────────────────────

    public function test_cancel_rejects_invoice_from_other_tenant(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        $userA = User::factory()->create(['company_id' => $companyA->id]);
        $userB = User::factory()->create(['company_id' => $companyB->id]);

        Auth::login($userB);
        $invoiceB = Invoice::factory()->create(['company_id' => $companyB->id, 'type' => 'one_time', 'source' => 'manual']);

        Auth::login($userA);
        $this->expectException(NotFoundHttpException::class);
        BillingService::cancel($invoiceB, 'cross-tenant cancel attempt');
    }

    public function test_cancel_allows_own_tenant_invoice(): void
    {
        $companyA = Company::factory()->create();
        $userA = User::factory()->create(['company_id' => $companyA->id]);

        Auth::login($userA);
        $invoice = Invoice::factory()->create(['company_id' => $companyA->id, 'type' => 'one_time', 'source' => 'manual']);
        $result = BillingService::cancel($invoice, 'valid reason');

        $this->assertEquals('cancelled', $result->status);
    }

    // ── addItem() tenant recheck ─────────────────────────────────────

    public function test_add_item_rejects_invoice_from_other_tenant(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        $userA = User::factory()->create(['company_id' => $companyA->id]);
        $userB = User::factory()->create(['company_id' => $companyB->id]);

        Auth::login($userB);
        $invoiceB = Invoice::factory()->create(['company_id' => $companyB->id, 'type' => 'one_time', 'source' => 'manual']);

        Auth::login($userA);
        $this->expectException(NotFoundHttpException::class);
        BillingService::addItem($invoiceB, ['description' => 'Test', 'quantity' => 1, 'unit_price' => 100]);
    }

    // ── send() tenant recheck ────────────────────────────────────────

    public function test_send_rejects_invoice_from_other_tenant(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        $userA = User::factory()->create(['company_id' => $companyA->id]);
        $userB = User::factory()->create(['company_id' => $companyB->id]);

        Auth::login($userB);
        $invoiceB = Invoice::factory()->create(['company_id' => $companyB->id, 'type' => 'one_time', 'source' => 'manual']);
        BillingService::addItem($invoiceB, ['description' => 'Test', 'quantity' => 2, 'unit_price' => 50]);

        Auth::login($userA);
        $this->expectException(NotFoundHttpException::class);
        BillingService::send($invoiceB);
    }

    // ── Billing artisan commands: gateway path via resolveCompanyIds ──

    public function test_generate_command_creates_for_all_active_companies(): void
    {
        $companyA = Company::factory()->create(['is_active' => true]);
        $companyB = Company::factory()->create(['is_active' => true]);

        $createSubscriptionForCompany = function (Company $company, string $code, string $mrc): ServiceSubscription {
            $user = User::factory()->create(['company_id' => $company->id]);
            $this->actingAs($user);

            $customer = Customer::factory()->create([
                'company_id' => $company->id,
                'code' => 'CUS-'.$company->id.'-'.fake()->unique()->numberBetween(1000, 9999),
            ]);

            $address = CustomerAddress::factory()->create([
                'company_id' => $company->id,
                'customer_id' => $customer->id,
            ]);

            $bandwidthProfile = BandwidthProfile::query()->where('company_id', $company->id)->first()
                ?? BandwidthProfile::factory()->create([
                    'company_id' => $company->id,
                    'name' => 'BW-'.$company->id.'-'.fake()->unique()->numberBetween(100, 999),
                ]);

            $speedProfile = SpeedProfile::query()->where('company_id', $company->id)->first()
                ?? SpeedProfile::factory()->create([
                    'company_id' => $company->id,
                    'name' => 'SPD-'.$company->id.'-'.fake()->unique()->numberBetween(100, 999),
                ]);

            $slaTier = SLATier::query()->where('company_id', $company->id)->first()
                ?? SLATier::factory()->create([
                    'company_id' => $company->id,
                    'name' => 'SLA-'.$company->id.'-'.fake()->unique()->numberBetween(100, 999),
                ]);

            $package = ServicePackage::factory()->create([
                'company_id' => $company->id,
                'bandwidth_profile_id' => $bandwidthProfile->id,
                'speed_profile_id' => $speedProfile->id,
                'sla_tier_id' => $slaTier->id,
                'code' => 'PKG-'.$company->id.'-'.fake()->unique()->numberBetween(1000, 9999),
            ]);

            return ServiceSubscription::factory()->create([
                'company_id' => $company->id,
                'customer_id' => $customer->id,
                'service_package_id' => $package->id,
                'installation_address_id' => $address->id,
                'code' => $code,
                'status' => 'active',
                'activation_date' => '2026-02-10',
                'mrc_amount' => $mrc,
            ]);
        };

        $createSubscriptionForCompany($companyA, 'SUB-TENANT-A', '50000');
        $createSubscriptionForCompany($companyB, 'SUB-TENANT-B', '75000');

        // Logout to simulate Artisan context (no auth user)
        Auth::logout();
        CompanyService::resetCache();

        $this->artisan('billing:generate', ['--period' => '2026-03'])
            ->assertExitCode(0);

        // Verify both companies each received exactly 1 generated invoice
        $countA = Invoice::withoutCompany()->where('company_id', $companyA->id)->where('type', 'recurring')->count();
        $countB = Invoice::withoutCompany()->where('company_id', $companyB->id)->where('type', 'recurring')->count();

        $this->assertSame(1, $countA, 'Company A should have exactly 1 generated invoice');
        $this->assertSame(1, $countB, 'Company B should have exactly 1 generated invoice');
    }

    public function test_check_overdue_command_marks_all_active_companies(): void
    {
        $companyA = Company::factory()->create(['is_active' => true]);
        $companyB = Company::factory()->create(['is_active' => true]);

        $invoiceA = Invoice::factory()->create(['company_id' => $companyA->id, 'type' => 'one_time', 'source' => 'manual']);
        $invoiceA->update(['status' => 'sent', 'due_date' => now()->subDays(5)->toDateString(), 'total' => 100]);

        $invoiceB = Invoice::factory()->create(['company_id' => $companyB->id, 'type' => 'one_time', 'source' => 'manual']);
        $invoiceB->update(['status' => 'sent', 'due_date' => now()->subDays(5)->toDateString(), 'total' => 200]);

        // Logout to simulate Artisan context
        Auth::logout();
        CompanyService::resetCache();

        $this->artisan('billing:check-overdue')->assertExitCode(0);

        $invoiceA->refresh();
        $invoiceB->refresh();
        $this->assertEquals('overdue', $invoiceA->status, 'Company A invoice should be overdue');
        $this->assertEquals('overdue', $invoiceB->status, 'Company B invoice should be overdue');
    }

    public function test_check_overdue_command_with_company_option(): void
    {
        $companyA = Company::factory()->create(['is_active' => true]);
        $companyB = Company::factory()->create(['is_active' => true]);

        $invoiceA = Invoice::factory()->create(['company_id' => $companyA->id, 'type' => 'one_time', 'source' => 'manual']);
        $invoiceA->update(['status' => 'sent', 'due_date' => now()->subDays(5)->toDateString(), 'total' => 100]);

        $invoiceB = Invoice::factory()->create(['company_id' => $companyB->id, 'type' => 'one_time', 'source' => 'manual']);
        $invoiceB->update(['status' => 'sent', 'due_date' => now()->subDays(5)->toDateString(), 'total' => 200]);

        // Logout to simulate Artisan context
        Auth::logout();
        CompanyService::resetCache();

        $this->artisan('billing:check-overdue', ['--company' => $companyA->id])->assertExitCode(0);

        $invoiceA->refresh();
        $invoiceB->refresh();
        $this->assertEquals('overdue', $invoiceA->status, 'Company A invoice should be overdue');
        $this->assertEquals('sent', $invoiceB->status, 'Company B invoice should NOT be processed');
    }
}
