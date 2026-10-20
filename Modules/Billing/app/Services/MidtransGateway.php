<?php

namespace Modules\Billing\Services;

use App\Models\Core\Company;
use App\Models\Core\CompanyIntegration;
use App\Services\Core\IntegrationConfig;
use Illuminate\Http\Client\HttpClientException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\Billing\Exceptions\MidtransGatewayException;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\PaymentAttempt;

/**
 * The only code that talks to Midtrans (Snap token, Status API, cancel) or reads a server key. Every company has its
 * own merchant: the key, the mode and the notification URL come from the Midtrans row of the company in scope
 * (`company_integrations`), so a caller without an ambient company names one with CompanyService::runAs().
 * The key is never logged, returned or put in an exception message.
 */
class MidtransGateway
{
    /**
     * The company's gateway is off until its row is switched on and holds a server key, and a production host never runs
     * on sandbox keys by accident: that would book test payments as real ones. The owner can opt in explicitly for a
     * pilot (`allow_sandbox_in_production`, written by the import only).
     */
    public function isEnabled(): bool
    {
        return $this->usableRow() !== null;
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
        $row = $this->enabledRow();
        $gross = $attempt->grossAmount();
        $customer = $invoice->customer;
        $endpoint = $this->endpoint($row, 'app', '/snap/v1/transactions');
        // The company's own webhook, so its notifications reach this install without editing the Midtrans dashboard.
        $notificationUrl = route('webhooks.midtrans.company', Company::query()->whereKey($attempt->company_id)->value('public_id'));

        try {
            $response = $this->request($row)
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
                    // Every card goes through 3-D Secure; no company setting turns it off.
                    'credit_card' => ['secure' => true],
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
        $row = $this->enabledRow();

        try {
            $response = $this->request($row)->get($this->endpoint($row, 'api', '/v2/'.rawurlencode($orderId).'/status'));
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
        $row = $this->enabledRow();

        try {
            $response = $this->request($row)->post($this->endpoint($row, 'api', '/v2/'.rawurlencode($attempt->order_id).'/cancel'));
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
     * both notifications and Status API responses. The key is the one of the company in scope, switched on or not: what
     * a notification is signed with does not depend on whether the company currently takes payments.
     *
     * @param  array<string, mixed>  $payload
     */
    public function isValidSignature(array $payload): bool
    {
        $serverKey = (string) app(IntegrationConfig::class)->current('midtrans')?->secret('server_key');

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

    /**
     * "Uji koneksi": ask Midtrans about an order that cannot exist. A key it accepts answers "not found", a key it refuses
     * answers 401. Nothing is created and nothing is booked, and a row can be tested before it is switched on.
     *
     * @return string|null the Indonesian reason the check failed (never the key, never a response body), null when it passed
     */
    public function check(CompanyIntegration $integration): ?string
    {
        if ($integration->secret('server_key') === null) {
            return 'Server key Midtrans belum diisi.';
        }

        try {
            $response = $this->request($integration)->timeout(5)->connectTimeout(3)
                ->get($this->endpoint($integration, 'api', '/v2/INB-PING-'.Str::ulid().'/status'));
        } catch (HttpClientException) {
            return 'Midtrans tidak dapat dihubungi dalam 5 detik.';
        }

        $status = $response->status();
        $code = $response->json('status_code');
        $code = is_scalar($code) ? (string) $code : null;

        return match (true) {
            $status === 401 || $code === '401' => 'Server key ditolak Midtrans. Periksa key dan mode: sandbox memakai awalan SB-Mid-server-, produksi Mid-server-.',
            $status === 404 || ($status === 200 && $code === '404') => null,
            $status >= 500 => "Midtrans sedang bermasalah (HTTP {$status}). Coba lagi nanti.",
            default => "Jawaban Midtrans tidak terduga (HTTP {$status}).",
        };
    }

    /**
     * The company a Midtrans notification is for: the one named in the URL (`webhooks.midtrans.company`, subsidiaries
     * included), else, on the legacy URL, the one that owns the order. Null when neither is known. Webhook routes have no
     * route-model binding, so the parameter is the public id as sent.
     */
    public static function companyIdForNotification(Request $request): ?int
    {
        if ($request->route()?->hasParameter('company')) {
            $publicId = $request->route('company');
            $id = is_string($publicId) ? Company::query()->wherePublicId($publicId)->value('id') : null;
        } else {
            $orderId = $request->json('order_id');
            $id = is_string($orderId) ? PaymentAttempt::withoutCompany()->where('order_id', $orderId)->value('company_id') : null;
        }

        return $id === null ? null : (int) $id;
    }

    /** The row of the company in scope, when it may take payments. */
    private function usableRow(): ?CompanyIntegration
    {
        $row = app(IntegrationConfig::class)->current('midtrans');

        if ($row?->is_enabled !== true || $row->secret('server_key') === null) {
            return null;
        }

        return ! app()->isProduction() || (bool) $row->setting('is_production') || (bool) $row->setting('allow_sandbox_in_production')
            ? $row
            : null;
    }

    private function enabledRow(): CompanyIntegration
    {
        return $this->usableRow() ?? throw new MidtransGatewayException('Midtrans is not configured.');
    }

    private function request(CompanyIntegration $row): PendingRequest
    {
        return Http::acceptJson()
            ->asJson()
            ->withBasicAuth((string) $row->secret('server_key'), '')
            ->timeout(10)
            ->connectTimeout(5);
    }

    /** `$host` is Midtrans' `app` (Snap) or `api` (Core API) subdomain. */
    private function endpoint(CompanyIntegration $row, string $host, string $path): string
    {
        $production = (bool) $row->setting('is_production');
        // A local mock may stand in for Midtrans, but only where no real money can flow.
        $override = ! $production && app()->environment(['local', 'testing', 'e2e']) ? $row->setting('base_url') : null;

        return rtrim($override ?: 'https://'.$host.($production ? '' : '.sandbox').'.midtrans.com', '/').$path;
    }
}
