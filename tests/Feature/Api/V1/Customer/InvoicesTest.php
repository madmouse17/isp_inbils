<?php

namespace Tests\Feature\Api\V1\Customer;

use App\Models\Core\Company;
use App\Models\Core\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Modules\Billing\Database\Factories\InvoiceFactory;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\Payment;
use Modules\Billing\Models\PaymentAttempt;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use Tests\Traits\InteractsWithMidtrans;
use Tests\Traits\InteractsWithMobileApi;

/**
 * The customer app's invoice list and detail: what the portal shows, to the token's customer only.
 */
class InvoicesTest extends TestCase
{
    use InteractsWithMidtrans;
    use InteractsWithMobileApi;
    use RefreshDatabase;

    private User $customerUser;

    private Customer $customer;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->signInCustomer();
    }

    private function signInCustomer(): void
    {
        $this->enableMidtrans();
        $this->fakeMidtrans();

        [$this->customerUser, $this->customer] = $this->customerLogin();
        $this->token = $this->issueToken($this->customerUser, 'customer');
    }

    /** @param  array<string, mixed>  $attributes */
    private function invoiceOf(Customer $customer, array $attributes = []): Invoice
    {
        return InvoiceFactory::new()->create([
            'company_id' => $customer->company_id,
            'customer_id' => $customer->id,
            'status' => 'sent',
            'total' => 154600,
            ...$attributes,
        ]);
    }

    /** @param  array<string, mixed>  $attributes */
    private function paymentOn(Invoice $invoice, array $attributes = []): Payment
    {
        return Payment::create([
            'company_id' => $invoice->company_id,
            'invoice_id' => $invoice->id,
            'amount' => '54600.00',
            'method' => 'transfer',
            'reference' => 'BANK-REF-'.Str::upper(Str::random(8)),
            'paid_at' => now(),
            'received_by' => User::factory()->create()->id,
            'notes' => 'cash desk note',
            ...$attributes,
        ]);
    }

    private function reverse(Payment $payment): void
    {
        Payment::withoutCompany()->whereKey($payment->id)->toBase()->update(['cancelled_at' => now()]);
    }

    private function revokeCustomerPermission(string $permission): void
    {
        Role::findByName('customer', 'web')->revokePermissionTo($permission);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function assertNoInternalFields(TestResponse $response): void
    {
        $body = (string) $response->getContent();

        foreach (['snap_token', 'order_id', 'last_notification', 'received_by', 'notes', 'reference', 'status_reason', 'transaction_id', 'cancel_reason', 'created_by'] as $key) {
            $this->assertStringNotContainsString('"'.$key.'":', $body, "{$key} leaked into the response");
        }

        foreach (['INTERNAL', 'BANK-REF', 'cash desk note', self::FAKE_SERVER_KEY, 'SB-Mid-client'] as $value) {
            $this->assertStringNotContainsString($value, $body, "{$value} leaked into the response");
        }
    }

    /** @return list<string> */
    private function numbers(TestResponse $response): array
    {
        return array_column($response->json('data'), 'number');
    }

    private function list(string $query = ''): TestResponse
    {
        return $this->api('GET', 'customer/invoices'.$query, token: $this->token);
    }

    public function test_the_list_holds_only_the_token_customers_issued_invoices(): void
    {
        foreach (['sent', 'partial', 'overdue', 'paid', 'cancelled', 'draft'] as $status) {
            $this->invoiceOf($this->customer, ['number' => 'INV-MINE-'.strtoupper($status), 'status' => $status]);
        }
        [, $neighbour] = $this->customerLogin($this->customerUser->company);
        $this->invoiceOf($neighbour, ['number' => 'INV-NEIGHBOUR']);
        [, $stranger] = $this->customerLogin(Company::factory()->create(['is_active' => true]));
        $this->invoiceOf($stranger, ['number' => 'INV-OTHER-COMPANY']);

        $response = $this->list()->assertOk();

        $this->assertEqualsCanonicalizing(
            ['INV-MINE-SENT', 'INV-MINE-PARTIAL', 'INV-MINE-OVERDUE', 'INV-MINE-PAID', 'INV-MINE-CANCELLED'],
            $this->numbers($response),
        );
        $issued = DB::table('invoices')->where('customer_id', $this->customer->id)->where('status', '!=', 'draft')->count();
        $this->assertSame(5, $issued);
        $response->assertJsonPath('meta.total', $issued);
    }

    public function test_a_list_item_is_the_portal_scalars_without_items_payments_or_the_pay_block(): void
    {
        $invoice = $this->invoiceOf($this->customer, ['status' => 'partial', 'paid_amount' => 54600]);
        $invoice->items()->create(['company_id' => $invoice->company_id, 'description' => 'Internet 20 Mbps', 'quantity' => 1, 'unit_price' => 154600, 'discount_amount' => 0, 'tax_rate' => 0, 'line_total' => 154600]);
        $this->paymentOn($invoice);

        $item = $this->list()->assertOk()->json('data.0');

        $this->assertEqualsCanonicalizing(
            ['id', 'number', 'issue_date', 'due_date', 'status', 'subtotal', 'tax_amount', 'discount_amount', 'total', 'paid_amount', 'sisa'],
            array_keys($item),
        );
        $this->assertSame($invoice->id, $item['id']);
        $this->assertSame('154600.00', $item['total']);
        $this->assertSame('100000.00', $item['sisa']);
    }

    public function test_unpaid_is_the_open_invoices_with_the_earliest_due_date_first(): void
    {
        $this->invoiceOf($this->customer, ['number' => 'INV-LATER', 'status' => 'sent', 'due_date' => now()->addDays(20)->toDateString()]);
        $this->invoiceOf($this->customer, ['number' => 'INV-SOON', 'status' => 'partial', 'paid_amount' => 54600, 'due_date' => now()->addDays(5)->toDateString()]);
        $this->invoiceOf($this->customer, ['number' => 'INV-LATE', 'status' => 'overdue', 'due_date' => now()->subDays(3)->toDateString()]);
        $this->invoiceOf($this->customer, ['number' => 'INV-SOON-TIE', 'status' => 'sent', 'due_date' => now()->addDays(5)->toDateString()]);
        $this->invoiceOf($this->customer, ['number' => 'INV-PAID', 'status' => 'paid', 'paid_amount' => 154600, 'due_date' => now()->subDays(30)->toDateString()]);
        $this->invoiceOf($this->customer, ['number' => 'INV-CANCELLED', 'status' => 'cancelled']);
        $this->invoiceOf($this->customer, ['number' => 'INV-DRAFT', 'status' => 'draft', 'due_date' => now()->subDays(60)->toDateString()]);

        $response = $this->list('?status=unpaid')->assertOk();

        $this->assertSame(['INV-LATE', 'INV-SOON', 'INV-SOON-TIE', 'INV-LATER'], $this->numbers($response));
        $response->assertJsonPath('meta.total', 4);
    }

    public function test_paid_is_only_paid_and_both_it_and_all_run_newest_issue_date_first(): void
    {
        $this->invoiceOf($this->customer, ['number' => 'INV-OLD', 'status' => 'paid', 'paid_amount' => 154600, 'issue_date' => now()->subDays(30)->toDateString()]);
        $this->invoiceOf($this->customer, ['number' => 'INV-MID-FIRST', 'status' => 'sent', 'issue_date' => now()->subDays(10)->toDateString()]);
        $this->invoiceOf($this->customer, ['number' => 'INV-MID-SECOND', 'status' => 'paid', 'paid_amount' => 154600, 'issue_date' => now()->subDays(10)->toDateString()]);
        $this->invoiceOf($this->customer, ['number' => 'INV-NEW', 'status' => 'cancelled', 'issue_date' => now()->subDay()->toDateString()]);

        $this->assertSame(['INV-MID-SECOND', 'INV-OLD'], $this->numbers($this->list('?status=paid')->assertOk()));
        $this->assertSame(['INV-NEW', 'INV-MID-SECOND', 'INV-MID-FIRST', 'INV-OLD'], $this->numbers($this->list('?status=all')->assertOk()));
    }

    public function test_the_status_defaults_to_all_and_an_unknown_one_is_refused(): void
    {
        $this->invoiceOf($this->customer, ['status' => 'sent']);
        $this->invoiceOf($this->customer, ['status' => 'paid', 'paid_amount' => 154600]);

        $this->list()->assertOk()->assertJsonPath('meta.total', 2);
        $this->list('?status=draft')->assertStatus(422)->assertJsonPath('code', 'validation_failed')->assertJsonValidationErrors('status');
    }

    public function test_pages_are_twenty_by_default_and_never_more_than_fifty(): void
    {
        foreach (range(1, 21) as $n) {
            $this->invoiceOf($this->customer, ['number' => sprintf('INV-PAGE-%02d', $n), 'issue_date' => now()->subDays($n)->toDateString()]);
        }

        $first = $this->list()->assertOk();
        $this->assertCount(20, $first->json('data'));
        $first->assertJsonPath('meta.per_page', 20)->assertJsonPath('meta.total', 21)->assertJsonStructure(['data', 'links' => ['next'], 'meta' => ['current_page', 'last_page', 'total']]);

        $second = $this->list('?per_page=5&page=2')->assertOk();
        $this->assertSame(['INV-PAGE-06', 'INV-PAGE-07', 'INV-PAGE-08', 'INV-PAGE-09', 'INV-PAGE-10'], $this->numbers($second));
        $second->assertJsonPath('meta.current_page', 2)->assertJsonPath('meta.per_page', 5);

        $this->assertCount(21, $this->list('?per_page=50')->assertOk()->json('data'));
        $this->list('?per_page=51')->assertStatus(422)->assertJsonValidationErrors('per_page');
        $this->list('?per_page=0')->assertStatus(422)->assertJsonValidationErrors('per_page');
        $this->list('?page=0')->assertStatus(422)->assertJsonValidationErrors('page');
    }

    public function test_the_detail_carries_items_payments_and_the_payment_block(): void
    {
        $invoice = $this->invoiceOf($this->customer, ['status' => 'partial', 'paid_amount' => 54600, 'notes' => 'INTERNAL: customer owes us a favour']);
        $invoice->items()->create(['company_id' => $invoice->company_id, 'description' => 'Internet 20 Mbps', 'quantity' => 1, 'unit_price' => 154600, 'discount_amount' => 0, 'tax_rate' => 0, 'line_total' => 154600]);
        $this->paymentOn($invoice, ['paid_at' => now()->subDay()]);
        $this->reverse($this->paymentOn($invoice, ['amount' => '1000.00']));

        $api = $this->api('GET', "customer/invoices/{$invoice->id}", token: $this->token)->assertOk();
        $api->assertJsonPath('data.sisa', '100000.00')
            ->assertJsonCount(1, 'data.items')
            ->assertJsonCount(1, 'data.payments')
            ->assertJsonPath('data.payment', ['enabled' => true, 'can_pay' => true, 'blocked_reason' => null]);
        $this->assertNoInternalFields($api);
    }

    public function test_the_payment_block_says_why_paying_is_blocked(): void
    {
        $cancelled = $this->invoiceOf($this->customer, ['status' => 'cancelled']);
        $paid = $this->invoiceOf($this->customer, ['status' => 'paid', 'paid_amount' => 154600]);

        $this->api('GET', "customer/invoices/{$cancelled->id}", token: $this->token)->assertOk()
            ->assertJsonPath('data.payment', ['enabled' => true, 'can_pay' => false, 'blocked_reason' => 'Tagihan sudah dibatalkan.']);
        $this->api('GET', "customer/invoices/{$paid->id}", token: $this->token)->assertOk()
            ->assertJsonPath('data.payment', ['enabled' => true, 'can_pay' => false, 'blocked_reason' => 'Tagihan sudah lunas.']);
    }

    public function test_without_a_gateway_the_block_says_payment_is_not_enabled(): void
    {
        $invoice = $this->invoiceOf($this->customer);
        $this->disableMidtrans();

        $this->api('GET', "customer/invoices/{$invoice->id}", token: $this->token)->assertOk()
            ->assertJsonPath('data.payment', ['enabled' => false, 'can_pay' => false, 'blocked_reason' => null]);
    }

    public function test_a_neighbours_invoice_another_companys_invoice_and_a_draft_are_404_never_403(): void
    {
        [, $neighbour] = $this->customerLogin($this->customerUser->company);
        $theirs = $this->invoiceOf($neighbour);
        [, $stranger] = $this->customerLogin(Company::factory()->create(['is_active' => true]));
        $elsewhere = $this->invoiceOf($stranger);
        $draft = $this->invoiceOf($this->customer, ['status' => 'draft']);

        foreach ([$theirs->id, $elsewhere->id, $draft->id, 999999] as $id) {
            $this->api('GET', "customer/invoices/{$id}", token: $this->token)->assertNotFound()->assertJsonPath('code', 'not_found');
        }
        $this->api('GET', 'customer/invoices/abc', token: $this->token)->assertNotFound();
    }

    public function test_only_a_customer_token_with_the_view_permission_may_read(): void
    {
        $invoice = $this->invoiceOf($this->customer);

        $this->api('GET', 'customer/invoices')->assertUnauthorized()->assertJsonPath('code', 'unauthenticated');

        $technician = $this->issueToken($this->technicianLogin(), 'technician');
        $this->api('GET', 'customer/invoices', token: $technician)->assertForbidden()->assertJsonPath('code', 'forbidden');
        $this->api('GET', "customer/invoices/{$invoice->id}", token: $technician)->assertForbidden();

        $this->revokeCustomerPermission('portal.invoice.view');

        $this->list()->assertForbidden()->assertJsonPath('code', 'forbidden');
        $this->api('GET', "customer/invoices/{$invoice->id}", token: $this->token)->assertForbidden();
    }

    public function test_a_flagged_legacy_password_is_refused_on_every_invoice_call(): void
    {
        $invoice = $this->invoiceOf($this->customer);
        $this->customerUser->forceFill(['must_change_password' => true])->save();

        $this->list()->assertForbidden()->assertJsonPath('code', 'password_reset_required');
        $this->api('GET', "customer/invoices/{$invoice->id}", token: $this->token)->assertForbidden()->assertJsonPath('code', 'password_reset_required');
    }

    public function test_no_gateway_ledger_or_staff_field_reaches_the_app(): void
    {
        $invoice = $this->invoiceOf($this->customer, ['notes' => 'INTERNAL: customer owes us a favour', 'status' => 'partial', 'paid_amount' => 54600]);
        $this->paymentOn($invoice);
        $attempt = PaymentAttempt::factory()->forInvoice($invoice)->create();

        foreach ([$this->list(), $this->api('GET', "customer/invoices/{$invoice->id}", token: $this->token)] as $response) {
            $response->assertOk();
            $this->assertNoInternalFields($response);
            $this->assertStringNotContainsString($attempt->snap_token, (string) $response->getContent());
            $this->assertStringNotContainsString($attempt->order_id, (string) $response->getContent());
            $this->assertStringNotContainsString('redirect_url', (string) $response->getContent());
        }
    }
}
