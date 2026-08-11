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
}
