<?php

namespace Tests\Feature\Billing;

use App\Models\Core\Company;
use App\Models\Core\Customer;
use App\Services\Core\CompanyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Modules\Billing\Database\Factories\InvoiceFactory;
use Modules\Billing\Database\Factories\PaymentAttemptFactory;
use Modules\Billing\Models\Payment;
use Modules\Billing\Models\PaymentAttempt;
use Modules\Billing\Services\MidtransGateway;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;
use Tests\Traits\InteractsWithMidtrans;
use Tests\Traits\InteractsWithMobileApi;

/**
 * Every company pays into its own Midtrans merchant: its own server key authenticates and signs, its own URL receives the
 * notifications, its own attempts are reconciled. Midtrans answers like the real one: an order belongs to the merchant
 * whose key asks about it.
 */
class MidtransPerCompanyTest extends TestCase
{
    use InteractsWithMidtrans;
    use InteractsWithMobileApi;
    use RefreshDatabase;

    private const KEY_A = 'SB-Mid-server-AAAA-key-of-company-a';

    private const KEY_B = 'SB-Mid-server-BBBB-key-of-company-b';

    private const SNAP_URL = 'https://app.sandbox.midtrans.com/snap/v1/transactions';

    private const FINISH_URL = 'https://inbils.test/pembayaran/selesai';

    private const KEY_REJECTED = 'Server key ditolak Midtrans. Periksa key dan mode: sandbox memakai awalan SB-Mid-server-, produksi Mid-server-.';

    private Company $a;

    private Company $b;

    protected function setUp(): void
    {
        parent::setUp();

        $this->a = Company::factory()->create();
        $this->b = Company::factory()->create();
        $this->midtransIntegration($this->a, self::KEY_A);
        $this->midtransIntegration($this->b, self::KEY_B);
        $this->fakeMidtrans();
    }

    /** @param  array<string, mixed>  $attempt */
    private function attemptFor(Company $company, array $attempt = []): PaymentAttempt
    {
        return CompanyService::runAs($company->id, function () use ($company, $attempt): PaymentAttempt {
            $invoice = InvoiceFactory::new()->create([
                'company_id' => $company->id,
                'customer_id' => Customer::factory()->create(['company_id' => $company->id])->id,
                'status' => 'sent',
                'total' => 100000,
            ]);

            return PaymentAttemptFactory::new()->forInvoice($invoice)->create($attempt);
        });
    }

    private function fresh(PaymentAttempt $attempt): PaymentAttempt
    {
        return PaymentAttempt::withoutCompany()->findOrFail($attempt->id);
    }

    /** @return list<string> */
    private function basic(string $key): array
    {
        return ['Basic '.base64_encode($key.':')];
    }

    private function webhookOf(Company $company): string
    {
        return '/webhooks/midtrans/'.$company->public_id;
    }

    /** The server key of company A or B, by company id. */
    private function keyOf(int $companyId): string
    {
        return [$this->a->id => self::KEY_A, $this->b->id => self::KEY_B][$companyId];
    }

    /**
     * Midtrans reports these attempts as settled, to whoever asks with the key of the company that owns them and signed
     * with that key. Any other key is refused, as at Midtrans: an order belongs to one merchant.
     */
    private function midtransSettles(PaymentAttempt ...$attempts): void
    {
        $this->midtransStatusReply = function (Request $request) use ($attempts) {
            foreach ($attempts as $attempt) {
                if (! str_contains($request->url(), $attempt->order_id)) {
                    continue;
                }

                $key = $this->keyOf($attempt->company_id);

                return $request->header('Authorization') === $this->basic($key)
                    ? Http::response($this->midtransPayload($attempt, key: $key))
                    : Http::response(['status_code' => '401', 'status_message' => 'Access denied due to unauthorized transaction, please check client or server key'], 401);
            }

            return Http::response(['status_code' => '404', 'status_message' => 'The requested resource is not found'], 404);
        };
    }

    private function snapFor(Company $company, PaymentAttempt $attempt): void
    {
        CompanyService::runAs($company->id, fn (): array => app(MidtransGateway::class)->createSnapTransaction($attempt, $attempt->invoice, self::FINISH_URL));
    }

    public function test_each_company_pays_into_its_own_merchant(): void
    {
        $attemptA = $this->attemptFor($this->a);
        $attemptB = $this->attemptFor($this->b);

        foreach ([[$this->a, $attemptA], [$this->b, $attemptB]] as [$company, $attempt]) {
            CompanyService::runAs($company->id, function () use ($attempt): void {
                $gateway = app(MidtransGateway::class);

                $gateway->createSnapTransaction($attempt, $attempt->invoice, self::FINISH_URL);
                $gateway->transactionStatus($attempt->order_id);
                $gateway->cancelTransaction($attempt);
            });
        }

        Http::assertSentCount(6);

        // The page, the lookup and the cancel call of an order carry the key of the company that owns it, and no other.
        foreach ([[$attemptA, self::KEY_A], [$attemptB, self::KEY_B]] as [$attempt, $key]) {
            $authorization = $this->basic($key);

            Http::assertSent(fn (Request $request): bool => $request->url() === self::SNAP_URL
                && $request->data()['transaction_details']['order_id'] === $attempt->order_id
                && $request->header('Authorization') === $authorization);
            Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
                && $request->url() === "https://api.sandbox.midtrans.com/v2/{$attempt->order_id}/status"
                && $request->header('Authorization') === $authorization);
            Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
                && $request->url() === "https://api.sandbox.midtrans.com/v2/{$attempt->order_id}/cancel"
                && $request->header('Authorization') === $authorization);
        }
    }

    public function test_each_company_uses_the_mode_of_its_own_account(): void
    {
        $this->midtransIntegration($this->b, 'Mid-server-BBBB-production-key', settings: ['is_production' => true]);
        Http::fake(['app.midtrans.com/snap/v1/transactions' => Http::response(['token' => 'tok-b', 'redirect_url' => 'https://app.midtrans.com/snap/v4/redirection/tok-b'], 201)]);
        $attemptA = $this->attemptFor($this->a);
        $attemptB = $this->attemptFor($this->b);

        $this->snapFor($this->a, $attemptA);
        $this->snapFor($this->b, $attemptB);

        Http::assertSent(fn (Request $request): bool => $request->url() === self::SNAP_URL && $request->data()['transaction_details']['order_id'] === $attemptA->order_id);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://app.midtrans.com/snap/v1/transactions'
            && $request->data()['transaction_details']['order_id'] === $attemptB->order_id
            && $request->header('Authorization') === $this->basic('Mid-server-BBBB-production-key'));
    }

    public function test_snap_overrides_the_notification_url_with_the_company_webhook(): void
    {
        URL::forceRootUrl('https://billing.example.com');
        URL::forceScheme('https');
        $attemptA = $this->attemptFor($this->a);
        $attemptB = $this->attemptFor($this->b);

        $this->snapFor($this->a, $attemptA);
        $this->snapFor($this->b, $attemptB);

        // Each company's notifications go to that company's own URL: no dashboard setting is shared between tenants.
        foreach ([[$attemptA, $this->a], [$attemptB, $this->b]] as [$attempt, $company]) {
            Http::assertSent(fn (Request $request): bool => ($request->data()['transaction_details']['order_id'] ?? null) === $attempt->order_id
                && $request->header('X-Override-Notification') === ['https://billing.example.com/webhooks/midtrans/'.$company->public_id]);
        }
    }

    public function test_the_company_webhook_verifies_with_that_company_key_only(): void
    {
        $attemptA = $this->attemptFor($this->a);
        $this->midtransSettles($attemptA);
        $signedByA = $this->midtransPayload($attemptA, key: self::KEY_A);

        // Company A's key on company B's URL: the signature is checked against B's key, so nothing is looked up or written.
        $this->postJson($this->webhookOf($this->b), $signedByA)->assertForbidden()->assertContent('');

        Http::assertNothingSent();
        $this->assertSame(0, Payment::withoutCompany()->count());
        $this->assertSame('pending', $this->fresh($attemptA)->status);
        $this->assertSame(0, Activity::query()->where('log_name', 'payment_attempt')->count());

        // The same notification on A's own URL is booked, in A.
        $this->postJson($this->webhookOf($this->a), $signedByA)->assertOk()->assertSee('OK');

        $this->assertSame('paid', $this->fresh($attemptA)->status);
        $this->assertSame($this->a->id, Payment::withoutCompany()->sole()->company_id);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET' && $request->header('Authorization') === $this->basic(self::KEY_A));
    }

    public function test_the_company_webhook_cannot_touch_another_company_attempt(): void
    {
        $attemptB = $this->attemptFor($this->b);
        $this->midtransSettles($attemptB);
        Log::spy();

        // Company A's key signs it and A's URL accepts the signature, yet the order is B's: to A it is a foreign order. It
        // is acknowledged (a merchant shared by two projects must not be retried for ever) and nothing is touched.
        $this->postJson($this->webhookOf($this->a), $this->midtransPayload($attemptB, key: self::KEY_A))->assertOk()->assertSee('OK');

        Log::shouldHaveReceived('info')->once()->with('midtrans.foreign_order', ['order_id' => $attemptB->order_id, 'verified' => true]);
        Http::assertNothingSent();
        $this->assertSame(0, Payment::withoutCompany()->count());
        $this->assertSame('pending', $this->fresh($attemptB)->status);
        $this->assertSame(0, Activity::query()->where('log_name', 'payment_attempt')->count());
    }

    public function test_signed_unknown_order_answers_200_and_writes_nothing(): void
    {
        $attemptA = $this->attemptFor($this->a);
        Log::spy();
        // A merchant shared with another project: the notification is signed with the company's own key, for an order this install never made.
        $foreign = $this->midtransPayload($attemptA, ['order_id' => 'OTHER-PROJECT-0001'], self::KEY_A);

        $this->postJson($this->webhookOf($this->a), $foreign)->assertOk()->assertSee('OK');
        // The signature is still checked first: a forged notification for an unknown order is refused as any other.
        $this->postJson($this->webhookOf($this->a), [...$foreign, 'signature_key' => str_repeat('a', 128)])->assertForbidden()->assertContent('');
        // And the key of another company does not pass for this one.
        $this->postJson($this->webhookOf($this->a), $this->midtransPayload($attemptA, ['order_id' => 'OTHER-PROJECT-0001'], self::KEY_B))->assertForbidden();

        Log::shouldHaveReceived('info')->once()->with('midtrans.foreign_order', ['order_id' => 'OTHER-PROJECT-0001', 'verified' => true]);
        Http::assertNothingSent();
        $this->assertSame(0, Payment::withoutCompany()->count());
        $this->assertSame(0, Activity::query()->where('log_name', 'payment_attempt')->count());
        $this->assertSame('pending', $this->fresh($attemptA)->status);
    }

    public function test_the_legacy_webhook_finds_the_company_from_the_order_id(): void
    {
        $attemptA = $this->attemptFor($this->a);
        $attemptB = $this->attemptFor($this->b);
        $this->midtransSettles($attemptA, $attemptB);

        // The key that counts is the key of the company that owns the order, whoever signed.
        $this->postJson('/webhooks/midtrans', $this->midtransPayload($attemptB, key: self::KEY_A))->assertForbidden();

        Http::assertNothingSent();
        $this->assertSame(0, Payment::withoutCompany()->count());

        $this->postJson('/webhooks/midtrans', $this->midtransPayload($attemptA, key: self::KEY_A))->assertOk();
        $this->postJson('/webhooks/midtrans', $this->midtransPayload($attemptB, key: self::KEY_B))->assertOk();

        $this->assertSame($this->a->id, Payment::withoutCompany()->where('reference', $attemptA->order_id)->value('company_id'));
        $this->assertSame($this->b->id, Payment::withoutCompany()->where('reference', $attemptB->order_id)->value('company_id'));
        foreach ([[$attemptA, self::KEY_A], [$attemptB, self::KEY_B]] as [$attempt, $key]) {
            Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
                && str_contains($request->url(), $attempt->order_id)
                && $request->header('Authorization') === $this->basic($key));
        }
    }

    public function test_a_subsidiary_has_its_own_webhook_url(): void
    {
        $subsidiary = Company::factory()->create();
        $subsidiary->forceFill(['parent_company_id' => $this->a->id])->save();
        $this->midtransIntegration($subsidiary, self::KEY_B);
        $attempt = $this->attemptFor($subsidiary);
        $this->midtransStatusReturns($this->midtransPayload($attempt, key: self::KEY_B));

        $this->postJson($this->webhookOf($subsidiary), $this->midtransPayload($attempt, key: self::KEY_B))->assertOk();

        $this->assertSame('paid', $this->fresh($attempt)->status);
        $this->assertSame($subsidiary->id, Payment::withoutCompany()->sole()->company_id);
    }

    public function test_a_url_that_names_no_company_is_not_found(): void
    {
        $attempt = $this->attemptFor($this->a);

        $this->postJson('/webhooks/midtrans/'.Str::ulid(), $this->midtransPayload($attempt, key: self::KEY_A))->assertNotFound();
        $this->postJson('/webhooks/midtrans/not-a-ulid', $this->midtransPayload($attempt, key: self::KEY_A))->assertNotFound();

        Http::assertNothingSent();
        $this->assertSame(0, Payment::withoutCompany()->count());
    }

    /** @return array<string, array{string}> */
    public static function companiesWithoutOnlinePayment(): array
    {
        return ['no row' => ['none'], 'a row switched off' => ['off'], 'an enabled row without a key' => ['keyless']];
    }

    #[DataProvider('companiesWithoutOnlinePayment')]
    public function test_a_company_without_midtrans_has_online_payment_off(string $case): void
    {
        $silent = Company::factory()->create();
        match ($case) {
            'off' => $this->midtransIntegration($silent, self::KEY_B, enabled: false),
            'keyless' => $this->midtransIntegration($silent, ''),
            default => null,
        };
        [$onUser, $onCustomer] = $this->customerLogin($this->a);
        [$offUser, $offCustomer] = $this->customerLogin($silent);
        $onInvoice = InvoiceFactory::new()->create(['company_id' => $this->a->id, 'customer_id' => $onCustomer->id, 'status' => 'sent', 'total' => 154600]);
        $offInvoice = InvoiceFactory::new()->create(['company_id' => $silent->id, 'customer_id' => $offCustomer->id, 'status' => 'sent', 'total' => 154600]);
        $onToken = $this->issueToken($onUser, 'customer');
        $offToken = $this->issueToken($offUser, 'customer');

        // The same install, the same moment: one company can take online payment, the other cannot.
        $this->api('GET', "customer/invoices/{$onInvoice->public_id}", token: $onToken)->assertOk()->assertJsonPath('data.payment.enabled', true);
        $this->api('GET', "customer/invoices/{$offInvoice->public_id}", token: $offToken)->assertOk()
            ->assertJsonPath('data.payment', ['enabled' => false, 'can_pay' => false, 'blocked_reason' => null]);
        $this->api('POST', "customer/invoices/{$offInvoice->public_id}/pay", token: $offToken)->assertNotFound()->assertJsonPath('code', 'payment_unavailable');
        $this->assertSame(0, PaymentAttempt::withoutCompany()->count());

        // Its webhook does not exist either, even for a notification that carries a valid signature of the key it once had.
        $attempt = $this->attemptFor($silent);
        $notification = $this->midtransPayload($attempt, key: self::KEY_B);
        $this->postJson($this->webhookOf($silent), $notification)->assertNotFound();
        $this->postJson('/webhooks/midtrans', $notification)->assertNotFound();

        Http::assertNothingSent();
        $this->assertSame(0, Payment::withoutCompany()->count());
        $this->assertSame('pending', $this->fresh($attempt)->status);
    }

    /** @return array<string, array{int}> */
    public static function notFoundAnswers(): array
    {
        return ['HTTP 404' => [404], 'HTTP 200 with a 404 body' => [200]];
    }

    #[DataProvider('notFoundAnswers')]
    public function test_the_check_passes_on_a_not_found_lookup(int $httpStatus): void
    {
        // A key can be tested before it is switched on.
        $row = $this->midtransIntegration($this->a, self::KEY_A, enabled: false);
        $this->midtransStatusReply = fn () => Http::response(['status_code' => '404', 'status_message' => 'The requested resource is not found'], $httpStatus);

        $this->assertNull(app(MidtransGateway::class)->check($row));

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && preg_match('~^https://api\.sandbox\.midtrans\.com/v2/INB-PING-[0-9A-Z]{26}/status$~', $request->url()) === 1
            && $request->header('Authorization') === $this->basic(self::KEY_A));
    }

    /** @return array<string, array{int}> */
    public static function rejectedKeyAnswers(): array
    {
        return ['HTTP 401' => [401], 'HTTP 200 with a 401 body' => [200]];
    }

    #[DataProvider('rejectedKeyAnswers')]
    public function test_the_check_explains_a_rejected_key_with_the_prefix_hint(int $httpStatus): void
    {
        $row = $this->midtransIntegration($this->a, self::KEY_A);
        $this->midtransStatusReply = fn () => Http::response(['status_code' => '401', 'status_message' => 'Access denied due to unauthorized transaction, please check client or server key'], $httpStatus);

        $message = app(MidtransGateway::class)->check($row);

        $this->assertSame(self::KEY_REJECTED, $message);
        $this->assertStringNotContainsString(self::KEY_A, $message);
    }

    public function test_the_check_reports_a_timeout(): void
    {
        $row = $this->midtransIntegration($this->a, self::KEY_A);
        $this->midtransStatusReply = fn () => throw new ConnectionException('cURL error 28: Operation timed out after 5001 milliseconds with key '.self::KEY_A);

        $message = app(MidtransGateway::class)->check($row);

        $this->assertSame('Midtrans tidak dapat dihubungi dalam 5 detik.', $message);
        $this->assertStringNotContainsString(self::KEY_A, $message);
    }

    /** @return array<string, array{int, string}> */
    public static function otherAnswers(): array
    {
        return [
            'Midtrans is down' => [503, 'Midtrans sedang bermasalah (HTTP 503). Coba lagi nanti.'],
            'a server error' => [500, 'Midtrans sedang bermasalah (HTTP 500). Coba lagi nanti.'],
            'a forbidden answer' => [403, 'Jawaban Midtrans tidak terduga (HTTP 403).'],
            'a transaction that cannot exist' => [200, 'Jawaban Midtrans tidak terduga (HTTP 200).'],
        ];
    }

    #[DataProvider('otherAnswers')]
    public function test_the_check_names_any_other_answer_without_quoting_it(int $httpStatus, string $expected): void
    {
        $row = $this->midtransIntegration($this->a, self::KEY_A);
        $this->midtransStatusReply = fn () => Http::response(['status_code' => '200', 'status_message' => 'echo '.self::KEY_A, 'transaction_status' => 'settlement'], $httpStatus);

        $this->assertSame($expected, app(MidtransGateway::class)->check($row));
    }

    public function test_the_check_tells_a_row_without_a_key_so_and_calls_nobody(): void
    {
        $row = $this->midtransIntegration($this->a, '');

        $this->assertSame('Server key Midtrans belum diisi.', app(MidtransGateway::class)->check($row));

        Http::assertNothingSent();
    }

    public function test_reconcile_skips_companies_without_midtrans(): void
    {
        $silent = Company::factory()->create();
        $off = Company::factory()->create();
        $this->midtransIntegration($off, self::KEY_B, enabled: false);
        // Past the grace period, so that the reconciliation asks about them.
        $aged = ['created_at' => now()->subMinutes(30)];
        $attemptA = $this->attemptFor($this->a, $aged);
        $attemptB = $this->attemptFor($this->b, $aged);
        $attemptSilent = $this->attemptFor($silent, $aged);
        $attemptOff = $this->attemptFor($off, $aged);
        $this->midtransSettles($attemptA, $attemptB);

        $this->artisan('midtrans:reconcile')->assertExitCode(0);

        // Each company is asked with its own key, and the two without an account are not asked at all.
        $this->assertSame('paid', $this->fresh($attemptA)->status);
        $this->assertSame('paid', $this->fresh($attemptB)->status);
        $this->assertSame($this->a->id, Payment::withoutCompany()->where('reference', $attemptA->order_id)->value('company_id'));
        $this->assertSame($this->b->id, Payment::withoutCompany()->where('reference', $attemptB->order_id)->value('company_id'));
        $this->assertSame('pending', $this->fresh($attemptSilent)->status);
        $this->assertSame('pending', $this->fresh($attemptOff)->status);
        Http::assertSentCount(2);
        foreach ([[$attemptA, self::KEY_A], [$attemptB, self::KEY_B]] as [$attempt, $key]) {
            Http::assertSent(fn (Request $request): bool => str_contains($request->url(), $attempt->order_id) && $request->header('Authorization') === $this->basic($key));
        }
    }

    public function test_reconcile_of_a_company_without_midtrans_says_so_and_calls_nobody(): void
    {
        $silent = Company::factory()->create();
        $attempt = $this->attemptFor($silent, ['created_at' => now()->subMinutes(30)]);

        $this->artisan('midtrans:reconcile', ['--company' => $silent->id])
            ->expectsOutputToContain('Midtrans is not configured; nothing to reconcile.')
            ->assertExitCode(0);

        Http::assertNothingSent();
        $this->assertSame('pending', $this->fresh($attempt)->status);
    }

    public function test_the_webhook_throttle_counts_only_unverified_calls(): void
    {
        $attemptA = $this->attemptFor($this->a);
        $attemptB = $this->attemptFor($this->b);
        $this->midtransSettles($attemptA, $attemptB);
        // Company A's key does not verify on company B's URL, so it counts as junk there like any wrong signature.
        $crossSigned = $this->midtransPayload($attemptA, key: self::KEY_A);

        for ($i = 0; $i < 30; $i++) {
            $this->postJson($this->webhookOf($this->b), $crossSigned)->assertForbidden();
        }
        $this->postJson($this->webhookOf($this->b), $crossSigned)->assertStatus(429);

        // Behind a proxy Midtrans shares that address with the junk, yet a notification that verifies still gets through, on either URL.
        $this->postJson($this->webhookOf($this->b), $this->midtransPayload($attemptB, key: self::KEY_B))->assertOk();
        $this->postJson('/webhooks/midtrans', $this->midtransPayload($attemptA, key: self::KEY_A))->assertOk();
        $this->assertSame(2, Payment::withoutCompany()->count());
    }
}
