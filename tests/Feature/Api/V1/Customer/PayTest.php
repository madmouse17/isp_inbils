<?php

namespace Tests\Feature\Api\V1\Customer;

use App\Models\Core\Company;
use App\Models\Core\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Modules\Billing\Database\Factories\InvoiceFactory;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\PaymentAttempt;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use Tests\Traits\InteractsWithMidtrans;
use Tests\Traits\InteractsWithMobileApi;

/**
 * Paying an invoice from the customer app: the Midtrans payment page for the remaining balance, reusing the open one.
 */
class PayTest extends TestCase
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

    /** @param  array<string, string|null>  $headers */
    private function pay(Invoice|int $invoice, ?string $token = null, array $headers = []): TestResponse
    {
        return $this->api('POST', 'customer/invoices/'.($invoice instanceof Invoice ? $invoice->id : $invoice).'/pay', token: $token ?? $this->token, headers: $headers);
    }

    private function snapCalls(): int
    {
        return Http::recorded(fn (Request $request): bool => str_contains($request->url(), '/snap/v1/transactions'))->count();
    }

    public function test_paying_returns_the_hosted_page_and_a_second_call_with_a_new_key_reuses_it(): void
    {
        $invoice = $this->invoiceOf($this->customer);

        $first = $this->pay($invoice)->assertOk();
        $attempt = PaymentAttempt::withoutCompany()->sole();

        $this->assertSame(['redirect_url', 'expires_at'], array_keys($first->json('data')));
        $this->assertSame($attempt->redirect_url, $first->json('data.redirect_url'));
        $this->assertStringStartsWith('https://app.sandbox.midtrans.com/snap/v4/redirection/', $first->json('data.redirect_url'));
        $this->assertSame($attempt->expires_at->toIso8601String(), $first->json('data.expires_at'));
        $this->assertSame('154600.00', $attempt->amount);

        $second = $this->pay($invoice)->assertOk();

        $this->assertSame($first->json('data'), $second->json('data'));
        $second->assertHeaderMissing('Idempotent-Replayed');
        $this->assertSame(1, PaymentAttempt::withoutCompany()->count());
        $this->assertSame(1, $this->snapCalls());
    }

    public function test_midtrans_is_sent_the_public_finish_page_not_the_portal_return_page(): void
    {
        $this->pay($this->invoiceOf($this->customer))->assertOk();

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/snap/v1/transactions')
            && $request->data()['callbacks']['finish'] === route('payments.finished')
            && $request->data()['callbacks']['error'] === route('payments.finished'));
        $this->assertSame('/pembayaran/selesai', parse_url(route('payments.finished'), PHP_URL_PATH));
    }

    public function test_the_same_idempotency_key_replays_the_identical_body(): void
    {
        $invoice = $this->invoiceOf($this->customer);
        $key = (string) Str::uuid();

        $first = $this->pay($invoice, headers: ['Idempotency-Key' => $key])->assertOk();
        $replay = $this->pay($invoice, headers: ['Idempotency-Key' => $key])->assertOk()->assertHeader('Idempotent-Replayed', 'true');

        $this->assertSame($first->getContent(), $replay->getContent());
        $this->assertSame(1, PaymentAttempt::withoutCompany()->count());
        $this->assertSame(1, $this->snapCalls());
    }

    public function test_a_pay_call_without_a_key_is_refused_before_anything_happens(): void
    {
        $invoice = $this->invoiceOf($this->customer);

        $this->pay($invoice, headers: ['Idempotency-Key' => null])->assertStatus(428)->assertJsonPath('code', 'idempotency_key_required');

        $this->assertSame(0, PaymentAttempt::withoutCompany()->count());
        Http::assertNothingSent();
    }

    public function test_the_page_is_for_the_remaining_balance_of_a_part_paid_invoice(): void
    {
        $invoice = $this->invoiceOf($this->customer, ['status' => 'partial', 'paid_amount' => 54600]);

        $this->pay($invoice)->assertOk();

        $this->assertSame('100000.00', PaymentAttempt::withoutCompany()->sole()->amount);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/snap/v1/transactions') && $request->data()['transaction_details']['gross_amount'] === 100000);
    }

    public function test_with_no_gateway_pay_is_404_payment_unavailable_and_nothing_is_created(): void
    {
        $invoice = $this->invoiceOf($this->customer);
        $this->disableMidtrans();

        $this->pay($invoice)->assertNotFound()->assertJsonPath('code', 'payment_unavailable');

        $this->assertSame(0, PaymentAttempt::withoutCompany()->count());
        Http::assertNothingSent();
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function unpayable(): array
    {
        return [
            'paid' => [['status' => 'paid', 'paid_amount' => 154600], 'Tagihan sudah lunas.'],
            'cancelled' => [['status' => 'cancelled'], 'Tagihan sudah dibatalkan.'],
            'fractional rupiah' => [['total' => 154600.75], 'Nominal tagihan memuat pecahan rupiah. Hubungi kami untuk membayar.'],
        ];
    }

    /** @param  array<string, mixed>  $attributes */
    #[DataProvider('unpayable')]
    public function test_an_invoice_that_cannot_be_paid_online_is_422_with_the_services_reason(array $attributes, string $reason): void
    {
        $invoice = $this->invoiceOf($this->customer, $attributes);

        $this->pay($invoice)
            ->assertStatus(422)
            ->assertJsonPath('code', 'invoice_not_payable')
            ->assertJsonPath('message', $reason);

        $this->assertSame(0, PaymentAttempt::withoutCompany()->count());
        Http::assertNothingSent();
    }

    public function test_a_failed_snap_call_keeps_the_status_the_service_gives_and_leaves_no_attempt(): void
    {
        $invoice = $this->invoiceOf($this->customer);
        $this->midtransSnapReply = fn () => Http::response(['error_messages' => ['denied']], 500);

        $this->pay($invoice)
            ->assertStatus(422)
            ->assertJsonPath('code', 'validation_failed')
            ->assertJsonPath('errors.pay.0', 'Halaman pembayaran belum dapat disiapkan. Coba lagi sebentar lagi.');

        $this->assertSame(0, PaymentAttempt::withoutCompany()->count());
    }

    public function test_a_neighbours_invoice_another_companys_invoice_and_a_draft_are_404(): void
    {
        [, $neighbour] = $this->customerLogin($this->customerUser->company);
        [, $stranger] = $this->customerLogin(Company::factory()->create(['is_active' => true]));
        $ids = [
            $this->invoiceOf($neighbour)->id,
            $this->invoiceOf($stranger)->id,
            $this->invoiceOf($this->customer, ['status' => 'draft'])->id,
            999999,
        ];

        foreach ($ids as $id) {
            $this->pay($id)->assertNotFound()->assertJsonPath('code', 'not_found');
        }

        $this->assertSame(0, PaymentAttempt::withoutCompany()->count());
        Http::assertNothingSent();
    }

    public function test_the_seventh_pay_within_a_minute_is_429(): void
    {
        $invoice = $this->invoiceOf($this->customer);

        for ($call = 1; $call <= 6; $call++) {
            $this->pay($invoice)->assertOk();
        }
        $seventh = $this->pay($invoice);

        $seventh->assertStatus(429)->assertJsonPath('code', 'too_many_requests');
        $this->assertGreaterThan(0, (int) $seventh->headers->get('Retry-After'));
        $this->assertSame(1, PaymentAttempt::withoutCompany()->count());
    }

    public function test_a_customer_without_the_pay_permission_is_403_and_nothing_is_created(): void
    {
        $invoice = $this->invoiceOf($this->customer);
        $this->revokeCustomerPermission('portal.invoice.pay');

        $this->pay($invoice)->assertForbidden()->assertJsonPath('code', 'forbidden');
        $this->api('GET', "customer/invoices/{$invoice->id}", token: $this->token)->assertOk();

        $this->assertSame(0, PaymentAttempt::withoutCompany()->count());
        Http::assertNothingSent();
    }

    public function test_a_technician_token_and_a_missing_token_cannot_pay(): void
    {
        $invoice = $this->invoiceOf($this->customer);
        $technician = $this->issueToken($this->technicianLogin(), 'technician');

        $this->pay($invoice, $technician)->assertForbidden()->assertJsonPath('code', 'forbidden');
        $this->api('POST', "customer/invoices/{$invoice->id}/pay")->assertUnauthorized();

        $this->assertSame(0, PaymentAttempt::withoutCompany()->count());
        Http::assertNothingSent();
    }

    public function test_a_flagged_legacy_password_cannot_pay(): void
    {
        $invoice = $this->invoiceOf($this->customer);
        $this->customerUser->forceFill(['must_change_password' => true])->save();

        $this->pay($invoice)->assertForbidden()->assertJsonPath('code', 'password_reset_required');

        $this->assertSame(0, PaymentAttempt::withoutCompany()->count());
        Http::assertNothingSent();
    }

    public function test_the_answer_carries_no_snap_token_order_id_or_notification(): void
    {
        $response = $this->pay($this->invoiceOf($this->customer))->assertOk();

        $this->assertNoInternalFields($response);
    }
}
