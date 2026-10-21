<?php

namespace Tests\Feature\Billing;

use App\Models\Core\Company;
use App\Models\Core\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Billing\Database\Factories\InvoiceFactory;
use Modules\Billing\Database\Factories\PaymentAttemptFactory;
use Modules\Billing\Http\Resources\PaymentAttemptResource;
use Modules\Billing\Jobs\CancelMidtransTransactionJob;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\PaymentAttempt;
use Modules\Billing\Services\PaymentAttemptService;
use PHPUnit\Framework\Attributes\DataProvider;
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
        $this->grantRole($technician, 'technician');

        $this->actingAs($technician)->get(route('admin.invoices.show', $invoice))->assertRedirect(route('app-only'));
    }

    public function test_staff_can_change_an_attempt_only_by_closing_an_open_page_or_marking_an_exception_refunded(): void
    {
        $names = collect(Route::getRoutes()->getRoutesByName())->keys();

        $this->assertEqualsCanonicalizing(
            ['admin.payment-attempts.cancel', 'admin.payment-attempts.refund'],
            $names->filter(fn (string $name): bool => str_contains($name, 'attempt'))->values()->all(),
        );
    }

    public function test_a_pending_page_gives_the_payment_dialog_its_order_id_and_opening_time(): void
    {
        $invoice = $this->invoice();
        PaymentAttemptFactory::new()->forInvoice($invoice)->status('expired')->create(['created_at' => '2026-10-19 09:00:00']);
        $open = PaymentAttemptFactory::new()->forInvoice($invoice)->create(['created_at' => '2026-10-20 09:15:00']);

        // The invoice page warns before cash is booked: newest attempt first, so the open page leads.
        $this->actingAs($this->admin)->get(route('admin.invoices.show', $invoice))
            ->assertInertia(fn (Assert $page) => $page
                ->where('onlinePayments.attempts.data.0.status', 'pending')
                ->where('onlinePayments.attempts.data.0.order_id', $open->order_id)
                ->where('onlinePayments.attempts.data.0.created_at', '2026-10-20T09:15:00+00:00'));
    }

    private function reasonLabelOf(PaymentAttempt $attempt): ?string
    {
        return (new PaymentAttemptResource(PaymentAttempt::withoutCompany()->findOrFail($attempt->id)))->toArray(Request::create('/'))['reason_label'];
    }

    /** @return array<string, array{array<string, mixed>, string|null}> */
    public static function whatMidtransReported(): array
    {
        return [
            'a card the bank refused' => [['transaction_status' => 'deny', 'status_code' => '202', 'payment_type' => 'credit_card', 'fraud_status' => 'accept', 'channel_response_code' => '05', 'channel_response_message' => 'Do not honour'], 'Ditolak bank: Do not honour (05)'],
            'a refusal without a message' => [['transaction_status' => 'deny', 'status_code' => '202', 'fraud_status' => 'accept'], 'Ditolak bank atau penerbit kartu'],
            'a refusal by fraud detection' => [['transaction_status' => 'deny', 'status_code' => '202', 'fraud_status' => 'deny'], 'Ditolak sistem deteksi penipuan'],
            'cancelled at Midtrans' => [['transaction_status' => 'cancel', 'status_code' => '200'], 'Dibatalkan di Midtrans'],
            'failed at Midtrans' => [['transaction_status' => 'failure', 'status_code' => '202'], 'Gagal diproses Midtrans'],
            'money that does not match' => [['transaction_status' => 'settlement', 'gross_amount' => '1000.00'], 'Jumlah dari Midtrans tidak sama dengan jumlah percobaan ini, belum dibukukan'],
            'money returned for a page never settled' => [['transaction_status' => 'refund', 'status_code' => '200'], 'Midtrans melaporkan uang kembali untuk pembayaran yang tidak pernah dibukukan di sini'],
            'an expiry carries no reason' => [['transaction_status' => 'expire', 'status_code' => '407'], null],
        ];
    }

    /** @param  array<string, mixed>  $reported */
    #[DataProvider('whatMidtransReported')]
    public function test_the_reasons_the_service_stores_read_in_indonesian(array $reported, ?string $label): void
    {
        $this->fakeMidtrans();
        $attempt = PaymentAttemptFactory::new()->forInvoice($this->invoice())->create();

        app(PaymentAttemptService::class)->applyNotification($attempt, $this->midtransPayload($attempt, $reported));

        $this->assertSame($label, $this->reasonLabelOf($attempt));
        // The stored reason stays what it was, for the audit and the exception list.
        $this->assertSame($label === null, PaymentAttempt::withoutCompany()->findOrFail($attempt->id)->status_reason === null);
    }

    public function test_money_returned_after_it_was_booked_reads_in_indonesian(): void
    {
        $this->fakeMidtrans();
        $attempt = PaymentAttemptFactory::new()->forInvoice($this->invoice())->create();
        $service = app(PaymentAttemptService::class);

        $service->applyNotification($attempt, $this->midtransPayload($attempt));
        $service->applyNotification($attempt, $this->midtransPayload($attempt, ['transaction_status' => 'refund']));

        $this->assertSame('Midtrans melaporkan uang kembali setelah pembayaran dibukukan, batalkan pembayarannya bila uang memang dikembalikan', $this->reasonLabelOf($attempt));
    }

    public function test_reasons_that_are_indonesian_already_are_shown_as_they_are_and_none_is_hidden(): void
    {
        $this->fakeMidtrans();
        Bus::fake([CancelMidtransTransactionJob::class]);
        $service = app(PaymentAttemptService::class);

        // Money for an invoice that cannot take it: the reason is billing's own sentence.
        $paid = PaymentAttemptFactory::new()->forInvoice($this->invoice())->create();
        $paid->invoice->update(['status' => 'paid', 'paid_amount' => 100000]);
        $service->applyNotification($paid, $this->midtransPayload($paid));
        $refused = PaymentAttempt::withoutCompany()->findOrFail($paid->id);
        $this->assertSame('exception', $refused->status);
        $this->assertSame($refused->status_reason, $this->reasonLabelOf($paid));
        $this->assertStringNotContainsString('Billing refused', (string) $this->reasonLabelOf($paid));

        // The pages Inbils and staff closed.
        $open = PaymentAttemptFactory::new()->forInvoice($this->invoice())->create();
        $this->actingAs($this->admin);
        $service->cancel($open);
        $this->assertSame('Dibatalkan oleh staf dari halaman tagihan.', $this->reasonLabelOf($open));

        // A page that expired without Midtrans ever hearing of it.
        $unpicked = PaymentAttemptFactory::new()->forInvoice($this->invoice())->create();
        $service->markExpired($unpicked);
        $this->assertSame('Halaman bayar kedaluwarsa sebelum pelanggan memilih metode', $this->reasonLabelOf($unpicked));

        // Something it has never seen is not swallowed.
        $odd = PaymentAttemptFactory::new()->forInvoice($this->invoice())->status('exception')->create(['status_reason' => 'Sesuatu yang baru']);
        $this->assertSame('Sesuatu yang baru', $this->reasonLabelOf($odd));
    }

    /** @return array<string, array{string|null, array<string, mixed>, string|null}> */
    public static function paymentMethods(): array
    {
        return [
            'a virtual account of BCA' => ['bank_transfer', ['va_numbers' => [['bank' => 'bca', 'va_number' => '81234567890']]], 'VA BCA'],
            'a virtual account of BNI' => ['bank_transfer', ['va_numbers' => [['bank' => 'bni', 'va_number' => '81234567890']]], 'VA BNI'],
            'a virtual account of Permata' => ['bank_transfer', ['permata_va_number' => '81234567890'], 'VA Permata'],
            'a transfer that names no bank' => ['bank_transfer', [], 'Transfer bank'],
            'a bank that is not a word' => ['bank_transfer', ['va_numbers' => [['bank' => '<b>bca</b>']]], 'Transfer bank'],
            'Mandiri bill' => ['echannel', ['bill_key' => '123', 'biller_code' => '70012'], 'VA Mandiri'],
            'a card' => ['credit_card', ['masked_card' => '48111111-1114'], 'Kartu •••• 1114'],
            'a card without a number' => ['credit_card', [], 'Kartu kredit'],
            'GoPay' => ['gopay', [], 'GoPay'],
            'QRIS' => ['qris', [], 'QRIS'],
            'a store' => ['cstore', ['store' => 'alfamart'], 'Alfamart'],
            'a store that is no word' => ['cstore', ['store' => 'a b'], 'Gerai'],
            'a type it has never seen' => ['new_wallet', [], 'New Wallet'],
            'no method picked yet' => [null, [], null],
        ];
    }

    /** @param  array<string, mixed>  $notification */
    #[DataProvider('paymentMethods')]
    public function test_how_the_customer_paid_reads_as_staff_say_it(?string $type, array $notification, ?string $label): void
    {
        $attempt = PaymentAttemptFactory::new()->forInvoice($this->invoice())->create(['payment_type' => $type, 'last_notification' => $notification]);

        $resource = (new PaymentAttemptResource($attempt))->toArray(Request::create('/'));

        $this->assertSame($label, $resource['method_label']);
        // Of a card only the last four digits, and of a virtual account never its number.
        $json = (string) json_encode($resource);
        foreach (['48111111', '81234567890', 'bill_key', 'biller_code'] as $number) {
            $this->assertStringNotContainsString($number, $json);
        }
    }
}
