<?php

namespace Tests\Unit\Support\Observability;

use App\Support\Observability\LogRedactor;
use PHPUnit\Framework\TestCase;

class LogRedactorTest extends TestCase
{
    public function test_redacts_sensitive_keys_case_insensitive(): void
    {
        $input = [
            'user' => 'alice',
            'Password' => 'secret-value',
            'api_key' => 'k-123',
            'Authorization' => 'Bearer tok',
            'token' => 'abc',
            'credit_card' => '4111111111111111',
            'Card_Number' => '4111',
            'cvv' => '123',
            'ssn' => '111-22-3333',
            'nik' => '320101',
            'phone' => '081234',
            'Email' => 'a@b.c',
            'secret' => 'shh',
        ];

        $out = LogRedactor::redact($input);

        $this->assertSame('alice', $out['user']);
        foreach ([
            'Password', 'api_key', 'Authorization', 'token', 'credit_card',
            'Card_Number', 'cvv', 'ssn', 'nik', 'phone', 'Email', 'secret',
        ] as $key) {
            $this->assertSame('[REDACTED]', $out[$key], "expected {$key} redacted");
        }
    }

    public function test_redacts_credentials_private_keys_and_hyphenated_header_names(): void
    {
        $out = LogRedactor::redact([
            'credentials' => ['a' => 1],
            'Credential' => 'x',
            'private_key' => '-----BEGIN',
            'X-Api-Key' => 'k',
            'x-api-key' => 'k',
            'status' => 'ok',
        ]);

        foreach (['credentials', 'Credential', 'private_key', 'X-Api-Key', 'x-api-key'] as $key) {
            $this->assertSame('[REDACTED]', $out[$key], "expected {$key} redacted");
        }
        $this->assertSame('ok', $out['status']);
    }

    public function test_redacts_nested_arrays(): void
    {
        $out = LogRedactor::redact([
            'meta' => [
                'password' => 'x',
                'ok' => 1,
                'deep' => ['token' => 'y'],
            ],
        ]);

        $this->assertSame('[REDACTED]', $out['meta']['password']);
        $this->assertSame(1, $out['meta']['ok']);
        $this->assertSame('[REDACTED]', $out['meta']['deep']['token']);
    }

    public function test_leaves_non_sensitive_keys(): void
    {
        $out = LogRedactor::redact(['status' => 'ready', 'count' => 3]);

        $this->assertSame(['status' => 'ready', 'count' => 3], $out);
    }

    public function test_masks_otp_pin_code_bearer_cookie_session_server_key_signature_and_radius_secret(): void
    {
        $out = LogRedactor::redact([
            'otp' => '123456',
            'PIN' => '9876',
            'code' => 'AB-12',
            'recovery_code' => 'abcd-efgh',
            'bearer' => 'eyJhbGciOi',
            'Cookie' => 'inbils_session=abc',
            'session_id' => 's-1',
            'server_key' => 'SB-Mid-server-xyz',
            'X-Signature' => 'sha256=ff',
            'radius_secret' => 'testing123',
            'meta' => ['x_hub_signature' => 'sha256=aa', 'otp' => '654321', 'ok' => 'fine'],
        ]);

        foreach ([
            'otp', 'PIN', 'code', 'recovery_code', 'bearer', 'Cookie', 'session_id', 'server_key', 'X-Signature', 'radius_secret',
        ] as $key) {
            $this->assertSame('[REDACTED]', $out[$key], "expected {$key} redacted");
        }

        $this->assertSame('[REDACTED]', $out['meta']['x_hub_signature']);
        $this->assertSame('[REDACTED]', $out['meta']['otp']);
        $this->assertSame('fine', $out['meta']['ok']);
    }

    public function test_a_secret_key_masks_a_list_or_nested_value_whole(): void
    {
        $out = LogRedactor::redact([
            'recovery_codes' => ['abcd-efgh', 'ijkl-mnop'],
            'token' => ['access' => 'a-1', 'refresh' => 'r-1'],
            'meta' => ['ok' => 1],
        ]);

        $this->assertSame('[REDACTED]', $out['recovery_codes']);
        $this->assertSame('[REDACTED]', $out['token']);
        $this->assertSame(['ok' => 1], $out['meta']);
    }

    public function test_exact_short_keys_do_not_hide_error_code_postcode_or_shipping(): void
    {
        // otp, pin and code match only as the whole key: as substrings they would hide these.
        $input = ['error_code' => 'E42', 'postcode' => '40111', 'shipping' => 'jne', 'pinned' => true, 'opinion' => 'ok', 'http_code' => 502];

        $this->assertSame($input, LogRedactor::redact($input));
    }
}
