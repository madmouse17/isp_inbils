<?php

namespace Tests\Feature\Api\V1\Customer;

use App\Models\Core\Company;
use App\Models\Core\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Modules\Billing\Database\Factories\InvoiceFactory;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\Payment;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use Tests\Traits\InteractsWithMidtrans;
use Tests\Traits\InteractsWithMobileApi;

/**
 * The customer app's payment history: the customer's own live payments on issued invoices, newest first.
 */
class PaymentsTest extends TestCase
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
        $this->companyRoles((int) $this->customerUser->company_id)['customer']->revokePermissionTo($permission);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function assertNoInternalFields(TestResponse $response): void
    {
        $body = (string) $response->getContent();

        foreach (['snap_token', 'order_id', 'last_notification', 'received_by', 'notes', 'status_reason', 'transaction_id', 'cancel_reason', 'created_by'] as $key) {
            $this->assertStringNotContainsString('"'.$key.'":', $body, "{$key} leaked into the response");
        }

        foreach (['INTERNAL', 'BANK-REF', 'cash desk note', self::FAKE_SERVER_KEY, 'SB-Mid-client'] as $value) {
            $this->assertStringNotContainsString($value, $body, "{$value} leaked into the response");
        }
    }

    private function history(string $query = ''): TestResponse
    {
        return $this->api('GET', 'customer/payments'.$query, token: $this->token);
    }

    /** @return list<string> */
    private function ids(TestResponse $response): array
    {
        return array_column($response->json('data'), 'id');
    }

    public function test_the_history_holds_only_the_customers_live_payments_on_issued_invoices_newest_first(): void
    {
        $january = $this->invoiceOf($this->customer, ['number' => 'INV-JAN', 'status' => 'paid', 'paid_amount' => 154600]);
        $february = $this->invoiceOf($this->customer, ['number' => 'INV-FEB', 'status' => 'partial', 'paid_amount' => 54600]);
        $cancelled = $this->invoiceOf($this->customer, ['number' => 'INV-CANCELLED', 'status' => 'cancelled']);
        $draft = $this->invoiceOf($this->customer, ['number' => 'INV-DRAFT', 'status' => 'draft']);
        [, $neighbour] = $this->customerLogin($this->customerUser->company);
        [, $stranger] = $this->customerLogin(Company::factory()->create(['is_active' => true]));

        $oldest = $this->paymentOn($january, ['paid_at' => Carbon::parse('2026-01-10 09:00:00')]);
        $sameMoment = $this->paymentOn($february, ['paid_at' => Carbon::parse('2026-02-10 09:00:00')]);
        $tied = $this->paymentOn($january, ['paid_at' => Carbon::parse('2026-02-10 09:00:00')]);
        $newest = $this->paymentOn($cancelled, ['paid_at' => Carbon::parse('2026-03-01 09:00:00')]);
        $this->reverse($this->paymentOn($february, ['paid_at' => Carbon::parse('2026-04-01 09:00:00')]));
        $this->paymentOn($draft, ['paid_at' => Carbon::parse('2026-05-01 09:00:00')]);
        $this->paymentOn($this->invoiceOf($neighbour), ['paid_at' => Carbon::parse('2026-06-01 09:00:00')]);
        $this->paymentOn($this->invoiceOf($stranger), ['paid_at' => Carbon::parse('2026-07-01 09:00:00')]);

        $response = $this->history()->assertOk();

        $this->assertSame([$newest->public_id, $tied->public_id, $sameMoment->public_id, $oldest->public_id], $this->ids($response));
        $live = DB::table('payments')->whereIn('invoice_id', [$january->id, $february->id, $cancelled->id])->whereNull('cancelled_at')->count();
        $this->assertSame(4, $live);
        $response->assertJsonPath('meta.total', $live);
    }

    public function test_a_payment_is_the_allow_listed_fields_only(): void
    {
        $invoice = $this->invoiceOf($this->customer, ['number' => 'INV-ONE', 'status' => 'partial', 'paid_amount' => 54600]);
        $payment = $this->paymentOn($invoice, ['method' => 'midtrans', 'reference' => '7-42-abcdef123456', 'paid_at' => Carbon::parse('2026-03-05 10:30:00')]);

        $response = $this->history()->assertOk();
        $item = $response->json('data.0');

        $this->assertEqualsCanonicalizing(['id', 'amount', 'method', 'method_label', 'reference', 'paid_at', 'invoice'], array_keys($item));
        $this->assertSame('Pembayaran online', $item['method_label']);
        $this->assertSame('7-42-abcdef123456', $item['reference']);
        $this->assertEqualsCanonicalizing(['id', 'number'], array_keys($item['invoice']));
        $this->assertSame($payment->public_id, $item['id']);
        $this->assertSame('54600.00', $item['amount']);
        $this->assertSame('midtrans', $item['method']);
        $this->assertSame(Carbon::parse('2026-03-05 10:30:00')->toIso8601String(), $item['paid_at']);
        $this->assertSame(['id' => $invoice->public_id, 'number' => 'INV-ONE'], $item['invoice']);
        $this->assertNoInternalFields($response);
    }

    public function test_a_manual_payments_staff_reference_never_reaches_the_customer(): void
    {
        $invoice = $this->invoiceOf($this->customer, ['status' => 'partial', 'paid_amount' => 54600]);
        $this->paymentOn($invoice, ['method' => 'cash']);

        $response = $this->history()->assertOk();

        $this->assertNull($response->json('data.0.reference'));
        $this->assertSame('Tunai', $response->json('data.0.method_label'));
        $this->assertNoInternalFields($response);
    }

    public function test_the_history_is_paginated_and_the_size_is_capped(): void
    {
        $invoice = $this->invoiceOf($this->customer, ['status' => 'partial', 'paid_amount' => 54600]);
        foreach (range(1, 3) as $day) {
            $this->paymentOn($invoice, ['amount' => '1000.00', 'paid_at' => Carbon::parse("2026-03-0{$day} 09:00:00")]);
        }

        $this->history()->assertOk()->assertJsonPath('meta.per_page', 20)->assertJsonPath('meta.total', 3);
        $page = $this->history('?per_page=2&page=2')->assertOk();
        $this->assertCount(1, $page->json('data'));
        $page->assertJsonPath('meta.current_page', 2)->assertJsonPath('meta.last_page', 2);
        $this->history('?per_page=51')->assertStatus(422)->assertJsonPath('code', 'validation_failed')->assertJsonValidationErrors('per_page');
        $this->history('?page=0')->assertStatus(422)->assertJsonValidationErrors('page');
    }

    public function test_the_history_does_not_query_once_per_payment(): void
    {
        // Sanctum re-stamps last_used_at once a minute; a slow run crossing that boundary adds one query.
        $this->freezeTime();
        $invoice = $this->invoiceOf($this->customer, ['status' => 'partial', 'paid_amount' => 54600]);
        $this->paymentOn($invoice, ['amount' => '1000.00']);

        $queries = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->history()->assertOk();

            return count(DB::getQueryLog());
        };
        // The first call also warms the permission cache and stamps the token's last use.
        $queries();
        $withOne = $queries();
        foreach (range(1, 5) as $ignored) {
            $this->paymentOn($this->invoiceOf($this->customer, ['status' => 'partial', 'paid_amount' => 54600]), ['amount' => '1000.00']);
        }

        $this->assertSame($withOne, $queries());
    }

    public function test_a_customer_with_no_payments_gets_an_empty_page(): void
    {
        $this->invoiceOf($this->customer);

        $this->history()->assertOk()->assertJsonPath('data', [])->assertJsonPath('meta.total', 0);
    }

    public function test_only_a_customer_token_with_the_view_permission_may_read(): void
    {
        $this->history()->assertOk();
        $this->api('GET', 'customer/payments')->assertUnauthorized()->assertJsonPath('code', 'unauthenticated');

        $technician = $this->issueToken($this->technicianLogin(), 'technician');
        $this->api('GET', 'customer/payments', token: $technician)->assertForbidden()->assertJsonPath('code', 'forbidden');

        $this->revokeCustomerPermission('portal.invoice.view');
        $this->history()->assertForbidden()->assertJsonPath('code', 'forbidden');
    }
}
