<?php

namespace Tests\Feature\Billing;

use App\Models\Core\Company;
use App\Models\Core\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Billing\Database\Factories\InvoiceFactory;
use Modules\Billing\Database\Factories\PaymentAttemptFactory;
use Modules\Billing\Models\Invoice;
use Tests\TestCase;
use Tests\Traits\CreatesCompanyUser;
use Tests\Traits\InteractsWithMidtrans;

class InvoiceOnlinePaymentsPanelTest extends TestCase
{
    use CreatesCompanyUser;
    use InteractsWithMidtrans;
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->admin = $this->createCompanyUser();
        $this->enableMidtrans();
    }

    private function invoice(?Company $company = null): Invoice
    {
        $companyId = $company->id ?? $this->admin->company_id;

        return InvoiceFactory::new()->create([
            'company_id' => $companyId,
            'customer_id' => Customer::factory()->create(['company_id' => $companyId])->id,
            'status' => 'sent',
            'total' => 100000,
        ]);
    }

    public function test_staff_see_every_attempt_with_its_midtrans_references_and_exception_reason(): void
    {
        $invoice = $this->invoice();
        $expired = PaymentAttemptFactory::new()->forInvoice($invoice)->status('expired')->create(['created_at' => now()->subDay()]);
        $exception = PaymentAttemptFactory::new()->forInvoice($invoice)->status('exception')->create([
            'status_reason' => 'Midtrans reported refund after settlement. Reverse the payment by hand if the money was returned.',
            'transaction_id' => '513f1f01-c9da-474c-9fc9-d5c64364b709',
            'payment_type' => 'bank_transfer',
        ]);

        $response = $this->actingAs($this->admin)->get(route('admin.invoices.show', $invoice))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Billing/Invoices/Show')
                ->where('onlinePayments.enabled', true)
                ->has('onlinePayments.attempts.data', 2)
                ->where('onlinePayments.attempts.data.0.id', $exception->public_id)
                ->where('onlinePayments.attempts.data.0.status', 'exception')
                ->where('onlinePayments.attempts.data.0.status_reason', $exception->status_reason)
                ->where('onlinePayments.attempts.data.0.transaction_id', '513f1f01-c9da-474c-9fc9-d5c64364b709')
                ->where('onlinePayments.attempts.data.0.payment_type', 'bank_transfer')
                ->where('onlinePayments.attempts.data.0.amount', '100000.00')
                ->where('onlinePayments.attempts.data.1.id', $expired->public_id)
                ->where('onlinePayments.attempts.data.1.status', 'expired'));

        $json = json_encode($response->viewData('page')['props']['onlinePayments']);
        foreach (['snap_token', 'redirect_url', 'last_notification', $exception->snap_token, self::FAKE_SERVER_KEY] as $secret) {
            $this->assertStringNotContainsString($secret, $json);
        }
    }

    public function test_an_invoice_without_attempts_while_the_gateway_is_off_shows_no_panel_data(): void
    {
        $invoice = $this->invoice();
        $this->disableMidtrans();

        $this->actingAs($this->admin)->get(route('admin.invoices.show', $invoice))
            ->assertInertia(fn (Assert $page) => $page
                ->where('onlinePayments.enabled', false)
                ->has('onlinePayments.attempts.data', 0));
    }

    public function test_the_panel_only_lists_this_invoices_attempts(): void
    {
        $invoice = $this->invoice();
        PaymentAttemptFactory::new()->forInvoice($this->invoice())->create();

        $this->actingAs($this->admin)->get(route('admin.invoices.show', $invoice))
            ->assertInertia(fn (Assert $page) => $page->has('onlinePayments.attempts.data', 0));
    }

    public function test_attempts_of_another_companys_invoice_are_out_of_reach(): void
    {
        $foreign = $this->invoice(Company::factory()->create(['is_active' => true]));
        PaymentAttemptFactory::new()->forInvoice($foreign)->create();

        $this->actingAs($this->admin)->get(route('admin.invoices.show', $foreign))->assertNotFound();
    }

    public function test_a_user_without_billing_view_cannot_open_the_page_and_sees_no_attempts(): void
    {
        $invoice = $this->invoice();
        PaymentAttemptFactory::new()->forInvoice($invoice)->create();
        $technician = User::factory()->create(['company_id' => $this->admin->company_id, 'email_verified_at' => now()]);
        $technician->assignRole('technician');

        $this->actingAs($technician)->get(route('admin.invoices.show', $invoice))->assertRedirect(route('app-only'));
    }

    public function test_no_staff_route_can_change_an_attempt(): void
    {
        $names = collect(Route::getRoutes()->getRoutesByName())->keys();

        $this->assertSame([], $names->filter(fn (string $name): bool => str_contains($name, 'attempt'))->values()->all());
    }
}
