<?php

namespace Modules\Billing\Services;

use Illuminate\Http\Client\HttpClientException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\Billing\Exceptions\MidtransGatewayException;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\PaymentAttempt;

/**
 * The only code that talks to Midtrans (Snap token, Status API, cancel) or reads its server key.
 * The key is never logged, returned or put in an exception message.
 */
class MidtransGateway
{
    /**
     * The gateway is off until a server key is configured, and a production host never runs on sandbox keys by
     * accident: that would book test payments as real ones. The owner can opt in explicitly for a pilot.
     */
    public function isEnabled(): bool
    {
        if (! filled(config('services.midtrans.server_key'))) {
            return false;
        }

        return ! app()->isProduction()
            || (bool) config('services.midtrans.is_production')
            || (bool) config('services.midtrans.allow_sandbox_in_production');
    }

    /**
     * Create the hosted payment page for an attempt.
     *
     * @return array{token: string, redirect_url: string}
     *
     * @throws MidtransGatewayException
     */
    public function createSnapTransaction(PaymentAttempt $attempt, Invoice $invoice, string $finishUrl): array
    {
        $gross = $attempt->grossAmount();
        $customer = $invoice->customer;
        $endpoint = $this->url('app', '/snap/v1/transactions');
        $notificationUrl = (string) config('services.midtrans.notification_url');

        try {
            $response = $this->client()
                // Lets an install point notifications at its own webhook without editing the Midtrans dashboard.
                ->when(str_starts_with($notificationUrl, 'https://'), fn ($request) => $request->withHeaders(['X-Override-Notification' => $notificationUrl]))
                ->post($endpoint, [
                    'transaction_details' => ['order_id' => $attempt->order_id, 'gross_amount' => $gross],
                    'item_details' => [[
                        'id' => Str::limit($invoice->number, 50, ''),
                        'price' => $gross,
                        'quantity' => 1,
                        'name' => Str::limit('Tagihan '.$invoice->number, 50, ''),
                    ]],
                    'customer_details' => array_filter([
                        'first_name' => $customer ? Str::limit($customer->name, 30, '') : null,
                        'email' => $customer?->email,
                    ]),
                    'expiry' => ['unit' => 'hours', 'duration' => PaymentAttempt::TTL_HOURS],
                    'page_expiry' => ['duration' => PaymentAttempt::TTL_HOURS, 'unit' => 'hours'],
                    'callbacks' => ['finish' => $finishUrl, 'error' => $finishUrl],
                ]);
        } catch (HttpClientException $e) {
            throw new MidtransGatewayException('Midtrans Snap request failed: '.class_basename($e), previous: $e);
        }

        if (! $response->successful()) {
            Log::warning('midtrans.snap_rejected', [
                'order_id' => $attempt->order_id,
                'http_status' => $response->status(),
                'error_messages' => $response->json('error_messages'),
            ]);

            throw new MidtransGatewayException('Midtrans Snap rejected the transaction (HTTP '.$response->status().').');
        }

        $token = $response->json('token');
        $redirectUrl = $response->json('redirect_url');

        // The customer is sent to this URL, so it must stay on the host that issued it.
        if (! is_string($token) || ! is_string($redirectUrl) || parse_url($redirectUrl, PHP_URL_HOST) !== parse_url($endpoint, PHP_URL_HOST)) {
            throw new MidtransGatewayException('Midtrans Snap returned an unusable response.');
        }

        return ['token' => $token, 'redirect_url' => $redirectUrl];
    }

    /**
     * Midtrans' own record of a transaction, or null when it has none (the customer never picked a
     * payment method, or the page expired). The answer comes straight from Midtrans over TLS, so it
     * is the authority; its signature is still checked whenever Midtrans supplies one.
     *
     * @return array<string, mixed>|null
     *
     * @throws MidtransGatewayException
     */
    public function transactionStatus(string $orderId): ?array
    {
        try {
            $response = $this->client()->get($this->url('api', '/v2/'.rawurlencode($orderId).'/status'));
        } catch (HttpClientException $e) {
            throw new MidtransGatewayException('Midtrans status request failed: '.class_basename($e), previous: $e);
        }

        if ($response->status() === 404) {
            return null;
        }

        $body = $response->json();

        if (! $response->successful() || ! is_array($body)) {
            throw new MidtransGatewayException('Midtrans status lookup failed (HTTP '.$response->status().').');
        }

        // Some lookups answer HTTP 200 with the "not found" code inside the body.
        if (($body['status_code'] ?? null) === '404') {
            return null;
        }

        if (isset($body['signature_key']) && ! $this->isValidSignature($body)) {
            throw new MidtransGatewayException('Midtrans status response failed signature verification.');
        }

        return $body;
    }

    /**
     * Have Midtrans cancel the transaction of a page Inbils closed, so a customer who still has the page open cannot pay
     * it. A transaction that is not there (the customer never picked a payment method) or is already settled, expired or
     * cancelled answers 404 or 412, in the status line or in the body: there is nothing left to cancel, so it is no error.
     *
     * @throws MidtransGatewayException
     */
    public function cancelTransaction(PaymentAttempt $attempt): void
    {
        try {
            $response = $this->client()->post($this->url('api', '/v2/'.rawurlencode($attempt->order_id).'/cancel'));
        } catch (HttpClientException $e) {
            throw new MidtransGatewayException('Midtrans cancel request failed: '.class_basename($e), previous: $e);
        }

        // Midtrans often answers HTTP 200 and keeps its verdict in the body.
        $code = $response->json('status_code');
        $code = is_scalar($code) ? (string) $code : null;

        if ($response->status() === 404 || in_array($code, ['404', '412'], true)) {
            return;
        }

        if (! $response->successful() || ($code !== null && ! str_starts_with($code, '2'))) {
            throw new MidtransGatewayException('Midtrans cancel failed (HTTP '.$response->status().($code === null ? '' : ', code '.$code).').');
        }
    }

    /**
     * SHA512(order_id + status_code + gross_amount + server key), compared in constant time. Works on
     * both notifications and Status API responses.
     *
     * @param  array<string, mixed>  $payload
     */
    public function isValidSignature(array $payload): bool
    {
        $serverKey = (string) config('services.midtrans.server_key');

        // An empty key would let anyone compute a "valid" signature from public fields.
        if ($serverKey === '') {
            return false;
        }

        foreach (['order_id', 'status_code', 'gross_amount', 'signature_key'] as $field) {
            if (! is_string($payload[$field] ?? null)) {
                return false;
            }
        }

        return hash_equals(hash('sha512', $payload['order_id'].$payload['status_code'].$payload['gross_amount'].$serverKey), $payload['signature_key']);
    }

    private function client(): PendingRequest
    {
        if (! $this->isEnabled()) {
            throw new MidtransGatewayException('Midtrans is not configured.');
        }

        return Http::acceptJson()
            ->asJson()
            ->withBasicAuth((string) config('services.midtrans.server_key'), '')
            ->timeout(10)
            ->connectTimeout(5);
    }

    /** `$host` is Midtrans' `app` (Snap) or `api` (Core API) subdomain. */
    private function url(string $host, string $path): string
    {
        $production = (bool) config('services.midtrans.is_production');
        // A local mock may stand in for Midtrans, but only where no real money can flow.
        $override = ! $production && app()->environment(['local', 'testing', 'e2e']) ? config('services.midtrans.base_url') : null;

        return rtrim($override ?: 'https://'.$host.($production ? '' : '.sandbox').'.midtrans.com', '/').$path;
    }
}
