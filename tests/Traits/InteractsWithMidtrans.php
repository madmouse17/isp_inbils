<?php

namespace Tests\Traits;

use App\Models\Core\Company;
use App\Models\Core\CompanyIntegration;
use Closure;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Modules\Billing\Models\PaymentAttempt;

/**
 * Each company's own Midtrans account (a `company_integrations` row), fake credentials and Midtrans-shaped payloads for
 * tests. The keys below are not real keys; a real one lives only in the integration row of a real install and is never
 * read by a test.
 */
trait InteractsWithMidtrans
{
    protected const FAKE_SERVER_KEY = 'SB-Mid-server-FAKE-test-key';

    /** @var (Closure(Request): mixed)|null answers the Snap create call; null = a valid page for the requested order */
    protected ?Closure $midtransSnapReply = null;

    /** @var (Closure(Request): mixed)|null answers the Status API; null = "no such transaction" */
    protected ?Closure $midtransStatusReply = null;

    /** @var (Closure(Request): mixed)|null answers POST /v2/{order_id}/cancel; null = Midtrans cancelled the transaction */
    protected ?Closure $midtransCancelReply = null;

    /** Whether a company created from now on gets an enabled row, see enableMidtrans(). */
    protected bool $midtransForNewCompanies = false;

    protected bool $midtransListening = false;

    /** @var array<string, mixed> what an enabled row says about the account; midtransSettings() changes it */
    protected array $midtransAccount = ['is_production' => false];

    /**
     * Every company, the ones that exist and the ones a test creates later, gets an enabled Midtrans row with the fake
     * key, so the order in which a test builds its world does not matter.
     */
    protected function enableMidtrans(): void
    {
        $this->midtransForNewCompanies = true;

        if (! $this->midtransListening) {
            // A model listener cannot be removed on its own (flushEventListeners would also drop the app's own hooks), so
            // disableMidtrans() switches this one off.
            Company::created(function (Company $company): void {
                if ($this->midtransForNewCompanies) {
                    $this->midtransIntegration($company);
                }
            });
            $this->midtransListening = true;
        }

        foreach (Company::query()->get() as $company) {
            $this->midtransIntegration($company);
        }
    }

    /** Every company's row is switched off, key kept, and a company created later gets none. */
    protected function disableMidtrans(): void
    {
        $this->midtransForNewCompanies = false;

        foreach (CompanyIntegration::withoutCompany()->where('provider', 'midtrans')->get() as $row) {
            $row->update(['is_enabled' => false]);
        }
    }

    /**
     * What the Midtrans rows say about the account (`is_production`, `base_url`, `allow_sandbox_in_production`), for the
     * rows there are and for the companies created later. Saved through the model, so a row read before is forgotten.
     *
     * @param  array<string, mixed>  $settings
     */
    protected function midtransSettings(array $settings): void
    {
        $this->midtransAccount = [...$this->midtransAccount, ...$settings];

        foreach (CompanyIntegration::withoutCompany()->where('provider', 'midtrans')->get() as $row) {
            $row->update(['settings' => [...($row->settings ?? []), ...$settings]]);
        }
    }

    /**
     * The company's own Midtrans account: enabled, on a sandbox account, with the fake key unless the test gives another
     * (an empty key is a row without a key).
     *
     * @param  array<string, mixed>|null  $settings  null: what midtransSettings() left
     */
    protected function midtransIntegration(Company $company, string $serverKey = self::FAKE_SERVER_KEY, bool $enabled = true, ?array $settings = null): CompanyIntegration
    {
        return CompanyIntegration::withoutCompany()->updateOrCreate(
            ['company_id' => $company->id, 'provider' => 'midtrans'],
            ['credentials' => ['server_key' => $serverKey], 'settings' => $settings ?? $this->midtransAccount, 'is_enabled' => $enabled],
        );
    }

    /**
     * Fake the three Midtrans endpoints once per test and refuse any other outbound call. `Http::fake()` keeps the
     * first stub that matches (the cancel call before the Status API, which also matches it), so tests change an
     * answer through the reply properties, never by faking again.
     */
    protected function fakeMidtrans(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'app.sandbox.midtrans.com/snap/v1/transactions' => fn (Request $request) => ($this->midtransSnapReply ?? function (Request $request) {
                $orderId = $request->data()['transaction_details']['order_id'];

                return Http::response(['token' => 'tok-'.$orderId, 'redirect_url' => 'https://app.sandbox.midtrans.com/snap/v4/redirection/tok-'.$orderId], 201);
            })($request),
            'api.sandbox.midtrans.com/v2/*/cancel' => fn (Request $request) => ($this->midtransCancelReply ?? fn () => Http::response(['status_code' => '200', 'status_message' => 'Success, transaction is canceled', 'transaction_status' => 'cancel']))($request),
            'api.sandbox.midtrans.com/v2/*' => fn (Request $request) => ($this->midtransStatusReply ?? fn () => Http::response(['status_code' => '404', 'status_message' => 'The requested resource is not found'], 404))($request),
        ]);
    }

    /** @param  array<string, mixed>  $payload */
    protected function midtransStatusReturns(array $payload, int $httpStatus = 200): void
    {
        $this->midtransStatusReply = fn () => Http::response($payload, $httpStatus);
    }

    /** @param  array<string, mixed>  $payload */
    protected function midtransCancelReturns(array $payload, int $httpStatus = 200): void
    {
        $this->midtransCancelReply = fn () => Http::response($payload, $httpStatus);
    }

    /**
     * A Status API answer / notification for the attempt, signed with the fake key unless the company has its own.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function midtransPayload(PaymentAttempt $attempt, array $overrides = [], string $key = self::FAKE_SERVER_KEY): array
    {
        $payload = array_merge([
            'transaction_time' => '2026-10-12 10:00:00',
            'transaction_status' => 'settlement',
            'transaction_id' => '513f1f01-c9da-474c-9fc9-d5c64364b709',
            'status_message' => 'midtrans payment notification',
            'status_code' => '200',
            'settlement_time' => '2026-10-12 10:00:20',
            'payment_type' => 'bank_transfer',
            'order_id' => $attempt->order_id,
            'merchant_id' => 'G000000000',
            'gross_amount' => (string) $attempt->amount,
            'fraud_status' => 'accept',
            'currency' => 'IDR',
        ], $overrides);

        return $this->signMidtransPayload($payload, $key);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function signMidtransPayload(array $payload, string $key = self::FAKE_SERVER_KEY): array
    {
        $payload['signature_key'] = hash('sha512', $payload['order_id'].$payload['status_code'].$payload['gross_amount'].$key);

        return $payload;
    }
}
