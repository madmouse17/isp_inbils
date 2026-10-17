<?php

namespace Tests\Feature\Billing;

use App\Models\Core\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Billing\Database\Factories\InvoiceFactory;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\PaymentAttempt;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use Tests\Traits\InteractsWithMobileApi;

/**
 * The one portal page left after the app-only cutover: Midtrans' return target for payments opened before it.
 * It stays outside redirect.app.only, so a customer-only login still sees the state of its own attempt.
 */
class PortalPaymentReturnTest extends TestCase
{
    use InteractsWithMobileApi;
    use RefreshDatabase;

    private User $customerUser;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        [$this->customerUser, $this->customer] = $this->customerLogin();
    }

    private function invoiceOf(Customer $customer): Invoice
    {
        return InvoiceFactory::new()->create([
            'company_id' => $customer->company_id,
            'customer_id' => $customer->id,
            'status' => 'sent',
            'total' => 154600,
        ]);
    }

    public function test_the_removed_invoice_and_password_pages_are_gone(): void
    {
        $invoice = $this->invoiceOf($this->customer);

        foreach (['/portal/invoices', "/portal/invoices/{$invoice->public_id}", '/portal/password'] as $url) {
            $this->actingAs($this->customerUser)->get($url)->assertNotFound();
        }
        $this->actingAs($this->customerUser)->post("/portal/invoices/{$invoice->public_id}/pay")->assertNotFound();
    }

    public function test_the_return_page_shows_the_server_state_of_the_customers_own_attempt(): void
    {
        $attempt = PaymentAttempt::factory()->forInvoice($invoice = $this->invoiceOf($this->customer))->create();

        $response = $this->actingAs($this->customerUser)->get(route('portal.payments.return', ['order_id' => $attempt->order_id, 'transaction_status' => 'settlement', 'status_code' => '200']));

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Portal/Payments/Return')
            ->where('attempt.status', 'pending')
            ->where('attempt.amount', '154600.00')
            ->where('attempt.invoice.number', $invoice->number));

        $json = json_encode($response->viewData('page')['props']);
        foreach ([$attempt->snap_token, 'redirect_url', 'snap_token', $attempt->order_id] as $secret) {
            $this->assertStringNotContainsString($secret, $json);
        }
    }

    public function test_the_return_page_never_shows_another_customers_attempt(): void
    {
        [, $other] = $this->customerLogin($this->customer->company);
        $theirs = PaymentAttempt::factory()->forInvoice($this->invoiceOf($other))->create();

        $this->actingAs($this->customerUser)->get(route('portal.payments.return', ['order_id' => $theirs->order_id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('attempt', null));
        $this->actingAs($this->customerUser)->get(route('portal.payments.return'))
            ->assertInertia(fn (Assert $page) => $page->where('attempt', null));
    }

    public function test_the_return_page_without_an_order_id_shows_the_latest_own_attempt(): void
    {
        $invoice = $this->invoiceOf($this->customer);
        PaymentAttempt::factory()->forInvoice($invoice)->status('expired')->create();
        $latest = PaymentAttempt::factory()->forInvoice($invoice)->status('paid')->create();

        $this->actingAs($this->customerUser)->get(route('portal.payments.return'))
            ->assertInertia(fn (Assert $page) => $page->where('attempt.status', $latest->status));
    }

    public function test_an_exception_attempt_does_not_leak_its_internal_reason_to_the_customer(): void
    {
        $attempt = PaymentAttempt::factory()->forInvoice($this->invoiceOf($this->customer))->status('exception')->create(['status_reason' => 'gross_amount "1.00" differs from the attempt amount']);

        $response = $this->actingAs($this->customerUser)->get(route('portal.payments.return', ['order_id' => $attempt->order_id]));

        $this->assertStringNotContainsString('gross_amount', json_encode($response->viewData('page')['props']));
    }

    public function test_a_customer_without_the_view_permission_sees_nothing(): void
    {
        Role::findByName('customer', 'web')->revokePermissionTo('portal.invoice.view');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAs($this->customerUser)->get(route('portal.payments.return'))->assertForbidden();
    }

    public function test_staff_without_the_customer_role_is_forbidden(): void
    {
        $admin = User::factory()->create(['company_id' => $this->customer->company_id]);
        $admin->assignRole('admin');
        $admin->givePermissionTo('portal.invoice.view');

        $this->actingAs($admin)->get(route('portal.payments.return'))->assertForbidden();
    }

    public function test_a_customer_login_without_a_customer_record_is_forbidden(): void
    {
        $orphan = User::factory()->create(['company_id' => $this->customer->company_id]);
        $orphan->assignRole('customer');

        $this->actingAs($orphan)->get(route('portal.payments.return'))->assertForbidden();
    }
}
