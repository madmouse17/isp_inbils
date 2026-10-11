<?php

namespace App\Services\WhatsApp;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use LogicException;

/**
 * The only code that talks to Meta's WhatsApp Cloud API (one install-wide sender, config/services.php).
 * Sends utility template messages and verifies what Meta sends back to the webhook. The access token only
 * ever travels in the Authorization header: it is not in a URL, a log line or an exception message, and
 * neither is a response body (it can echo a phone number).
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

    public function isConfigured(): bool
    {
        return filled(config('services.whatsapp.access_token')) && filled(config('services.whatsapp.phone_number_id'));
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
        $payload = ['name' => $template, 'language' => ['code' => $language]];

        if ($parameters !== []) {
            $payload['components'] = [[
                'type' => 'body',
                'parameters' => array_map(fn (string $value): array => ['type' => 'text', 'text' => self::parameterText($value)], $parameters),
            ]];
        }

        try {
            $response = $this->request()->post($this->messagesUrl(), [
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
     * X-Hub-Signature-256 is "sha256=" plus the HMAC-SHA256 of the raw request body keyed with the app secret.
     * Compared in constant time and never valid while the secret is empty (anyone can key an HMAC with '').
     */
    public function isValidSignature(#[\SensitiveParameter] string $rawBody, #[\SensitiveParameter] ?string $header): bool
    {
        $secret = (string) config('services.whatsapp.app_secret');

        if ($secret === '' || $header === null || ! str_starts_with($header, 'sha256=')) {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $rawBody, $secret), substr($header, 7));
    }

    /** Meta's one-off GET handshake when the callback URL is saved in the app dashboard. */
    public function isValidVerifyToken(#[\SensitiveParameter] ?string $token): bool
    {
        $expected = (string) config('services.whatsapp.webhook_verify_token');

        return $expected !== '' && $token !== null && hash_equals($expected, $token);
    }

    private function request(): PendingRequest
    {
        if (! $this->isConfigured()) {
            throw new LogicException('WhatsApp Cloud API is not configured.');
        }

        return Http::withToken((string) config('services.whatsapp.access_token'))
            ->acceptJson()
            ->asJson()
            ->timeout(10)
            ->connectTimeout(5);
    }

    private function messagesUrl(): string
    {
        return 'https://graph.facebook.com/'.config('services.whatsapp.graph_version').'/'.config('services.whatsapp.phone_number_id').'/messages';
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
