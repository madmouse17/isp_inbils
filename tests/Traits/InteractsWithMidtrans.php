<?php

namespace Tests\Traits;

use Closure;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Modules\Billing\Models\PaymentAttempt;

/**
 * Fake gateway credentials and Midtrans-shaped payloads for tests. The key below is not a real key; the
 * real one lives only in .env and is never read by a test.
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

    protected function enableMidtrans(): void
    {
        config([
            'services.midtrans.server_key' => self::FAKE_SERVER_KEY,
            'services.midtrans.client_key' => 'SB-Mid-client-FAKE',
            'services.midtrans.merchant_id' => 'G000000000',
            'services.midtrans.is_production' => false,
            'services.midtrans.base_url' => null,
        ]);
    }

    protected function disableMidtrans(): void
    {
        config(['services.midtrans.server_key' => null]);
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
     * A Status API answer / notification for the attempt, signed with the fake key.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function midtransPayload(PaymentAttempt $attempt, array $overrides = []): array
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

        return $this->signMidtransPayload($payload);
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
