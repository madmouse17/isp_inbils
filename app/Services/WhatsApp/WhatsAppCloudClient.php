<?php

namespace App\Services\WhatsApp;

use App\Models\Core\CompanyIntegration;
use App\Services\Core\IntegrationConfig;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use LogicException;

/**
 * The only code that talks to Meta's WhatsApp Cloud API. Every company sends from its own number with its own token (its
 * `whatsapp` row in company_integrations): the company in scope decides which, so a caller without an ambient company names
 * one with CompanyService::runAs(). Sends utility template messages and verifies what Meta sends back to the shared
 * webhook. The access token only ever travels in the Authorization header: it is not in a URL, a log line or an exception
 * message, and neither is a response body (it can echo a phone number).
 */
class WhatsAppCloudClient
{
    /**
     * Meta error codes that heal on their own, or once the owner replaces the token: the message stays queued
     * and the job is retried instead of failing for good (a failed row would block its dedupe key).
     * Auth (0, 3, 10, 190, 200-299), throttling (4, 80007, 130429, 131048, 131056, 131064) and server
     * trouble (1, 2, 131000, 131016, 131057, 133004), per Meta's error-codes reference.
     */
    private const RETRYABLE_CODES = [0, 1, 2, 3, 4, 10, 190, 80007, 130429, 131000, 131016, 131048, 131056, 131057, 131064, 133004];

    /** A body is read before it is trusted: at most this many of its numbers are looked up, each no longer than the column. */
    private const MAX_NUMBERS = 20;

    private const MAX_NUMBER_LENGTH = 100;

    /** Whether the company in scope may send: its row is switched on and holds a token and a phone number id. */
    public function isConfigured(): bool
    {
        return $this->usableRow() !== null;
    }

    /**
     * Send one approved template to a number given as full digits (62812...). Returns the provider message id
     * (wamid). Meta only accepts the request here; delivery and read arrive later through the webhook.
     *
     * @param  list<string>  $parameters  body parameters in template order
     *
     * @throws WhatsAppSendRejected Meta refused for good: do not retry
     * @throws WhatsAppTransientError Meta or the network failed in a way that can heal: retry
     */
    public function sendTemplate(#[\SensitiveParameter] string $to, string $template, string $language, #[\SensitiveParameter] array $parameters): string
    {
        $row = $this->usableRow() ?? throw new LogicException('WhatsApp Cloud API is not configured.');
        $payload = ['name' => $template, 'language' => ['code' => $language]];

        if ($parameters !== []) {
            $payload['components'] = [[
                'type' => 'body',
                'parameters' => array_map(fn (string $value): array => ['type' => 'text', 'text' => self::parameterText($value)], $parameters),
            ]];
        }

        try {
            $response = $this->request($row)->post($this->graphUrl($row).'/messages', [
                'messaging_product' => 'whatsapp',
                'recipient_type' => 'individual',
                'to' => $to,
                'type' => 'template',
                'template' => $payload,
            ]);
        } catch (ConnectionException) {
            throw new WhatsAppTransientError('connection');
        }

        if ($response->successful()) {
            $id = $response->json('messages.0.id');

            // Accepted without an id: the message may well have gone out, so a retry could send it twice.
            return is_string($id) && $id !== '' ? $id : throw new WhatsAppSendRejected('invalid_response');
        }

        $status = $response->status();
        $code = $response->json('error.code');
        $code = is_int($code) ? $code : null;
        $marker = $code !== null ? (string) $code : 'http_'.$status;

        if ($status >= 500 || in_array($status, [401, 403, 429], true) || ($code !== null && self::isRetryable($code))) {
            throw new WhatsAppTransientError($marker);
        }

        throw new WhatsAppSendRejected($marker);
    }

    /**
     * "Uji koneksi": does Meta know this number, and does the token reach it? One call, five seconds at most. The row is
     * the only input, so a company can test its account before it switches it on. The answer is the Indonesian reason the
     * check failed, or null when it passed; it never holds a token, a response body or a phone number.
     */
    public function check(CompanyIntegration $integration): ?string
    {
        $phoneNumberId = (string) $integration->external_id;

        if ($phoneNumberId === '' || $integration->secret('access_token') === null) {
            return 'ID nomor (phone_number_id) atau Access token belum diisi.';
        }

        try {
            $response = $this->request($integration)->timeout(5)->connectTimeout(3)
                ->get($this->graphUrl($integration).'?fields=display_phone_number,verified_name');
        } catch (ConnectionException) {
            return 'Meta tidak dapat dihubungi dalam 5 detik.';
        }

        $status = $response->status();
        $code = $response->json('error.code');
        $code = is_int($code) ? $code : null;

        return match (true) {
            $code === 190 || $status === 401 => 'Access token ditolak Meta (kedaluwarsa atau dicabut).',
            $code === 100 || $status === 404 => 'phone_number_id tidak ditemukan atau bukan milik token ini.',
            $code === 10 || ($code !== null && $code >= 200 && $code <= 299) || $status === 403 => 'Token tidak punya izin whatsapp_business_messaging untuk nomor ini.',
            $status >= 500 => "Meta sedang bermasalah (HTTP {$status}).",
            $status === 200 && (string) $response->json('id') === $phoneNumberId => null,
            default => "Jawaban Meta tidak terduga (HTTP {$status}).",
        };
    }

    /**
     * Whether the body was signed by a company that owns a number it names (see verifiedCompanies()).
     */
    public function isValidSignature(#[\SensitiveParameter] string $rawBody, #[\SensitiveParameter] ?string $header): bool
    {
        return $this->verifiedCompanies($rawBody, $header) !== [];
    }

    /**
     * X-Hub-Signature-256 is "sha256=" plus the HMAC-SHA256 of the raw request body keyed with the app secret. The webhook is
     * shared, so the number a payload names (`metadata.phone_number_id`) picks the switched-on rows that may own it, and only
     * a row whose own app secret signs the body keeps its company: sharing a number never lets one company's secret speak for
     * another. Compared in constant time and never valid while the secret is empty (anyone can key an HMAC with '').
     * Unsigned junk costs one indexed read and no write.
     *
     * @return array<string, list<int>> phone_number_id => the companies whose app secret signs $rawBody
     */
    public function verifiedCompanies(#[\SensitiveParameter] string $rawBody, #[\SensitiveParameter] ?string $header): array
    {
        if ($header === null || ! str_starts_with($header, 'sha256=')) {
            return [];
        }

        $numbers = self::numbersNamedBy(json_decode($rawBody, true));

        if ($numbers === []) {
            return [];
        }

        $signature = substr($header, 7);
        $companies = [];

        $rows = CompanyIntegration::withoutCompany()->where('provider', 'whatsapp')->where('is_enabled', true)->whereIn('external_id', $numbers)->get();

        foreach ($rows as $row) {
            $secret = $row->secret('app_secret');

            if ($secret !== null && hash_equals(hash_hmac('sha256', $rawBody, $secret), $signature)) {
                $companies[(string) $row->external_id][] = (int) $row->company_id;
            }
        }

        return $companies;
    }

    /**
     * Meta's one-off GET handshake when the callback URL is saved in the app dashboard. The URL is shared, so the verify
     * token of any switched-on company opens it; an empty token matches nothing.
     *
     * shortcut: decrypts every enabled WhatsApp row per handshake; add a hashed lookup column past ~1000 tenants.
     */
    public function isValidVerifyToken(#[\SensitiveParameter] ?string $token): bool
    {
        if ($token === null || $token === '') {
            return false;
        }

        foreach (CompanyIntegration::withoutCompany()->where('provider', 'whatsapp')->where('is_enabled', true)->cursor() as $row) {
            $expected = $row->secret('webhook_verify_token');

            if ($expected !== null && hash_equals($expected, $token)) {
                return true;
            }
        }

        return false;
    }

    /** The row of the company in scope, when it may send. */
    private function usableRow(): ?CompanyIntegration
    {
        $row = app(IntegrationConfig::class)->current('whatsapp');

        return $row?->is_enabled === true && $row->secret('access_token') !== null && filled($row->external_id) ? $row : null;
    }

    private function request(CompanyIntegration $row): PendingRequest
    {
        return Http::withToken((string) $row->secret('access_token'))
            ->acceptJson()
            ->asJson()
            ->timeout(10)
            ->connectTimeout(5);
    }

    /** The company's number on the Graph API. The id is the tenant's own setting, so it can only ever be one path segment. */
    private function graphUrl(CompanyIntegration $row): string
    {
        return 'https://graph.facebook.com/'.config('services.whatsapp.graph_version').'/'.rawurlencode((string) $row->external_id);
    }

    /**
     * The first distinct phone number ids a payload names (`entry.*.changes.*.value.metadata.phone_number_id`). Anything
     * else in their place is junk and skipped.
     *
     * @return list<string>
     */
    private static function numbersNamedBy(#[\SensitiveParameter] mixed $payload): array
    {
        if (! is_array($payload)) {
            return [];
        }

        $named = (array) data_get($payload, 'entry.*.changes.*.value.metadata.phone_number_id');
        $numbers = array_filter($named, fn (mixed $id): bool => is_string($id) && $id !== '' && strlen($id) <= self::MAX_NUMBER_LENGTH);

        return array_slice(array_unique($numbers), 0, self::MAX_NUMBERS);
    }

    private static function isRetryable(int $code): bool
    {
        return in_array($code, self::RETRYABLE_CODES, true) || ($code >= 200 && $code <= 299);
    }

    /** Meta rejects newlines, tabs, runs of more than four spaces and empty values inside a text parameter. */
    private static function parameterText(string $value): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $value));

        return $text === '' ? '-' : mb_substr($text, 0, 1024);
    }
}
