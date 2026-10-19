<?php

namespace App\Support\Observability;

final class LogRedactor
{
    /** @var list<string> */
    private const SENSITIVE = [
        'password',
        'token',
        'secret',
        'authorization',
        'api_key',
        'credit_card',
        'card_number',
        'cvv',
        'ssn',
        'nik',
        'phone',
        'email',
        'bearer',
        'cookie',
        'session',
        'server_key',
        'signature',
        'credential',
        'private_key',
        'private-key',
        'api-key',
    ];

    /**
     * Matched only as the whole key: as substrings they would hide error_code, postcode and shipping.
     *
     * @var list<string>
     */
    private const SENSITIVE_EXACT = [
        'otp',
        'pin',
        'code',
        'recovery_code',
        'recovery_codes',
    ];

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public static function redact(array $context): array
    {
        $out = [];

        foreach ($context as $key => $value) {
            if (self::isSensitive((string) $key)) {
                // The whole value goes: a list or an array under a secret key would leak through subkeys that look harmless.
                $out[$key] = '[REDACTED]';
            } else {
                $out[$key] = is_array($value) ? self::redact($value) : $value;
            }
        }

        return $out;
    }

    private static function isSensitive(string $key): bool
    {
        $normalized = strtolower($key);

        if (in_array($normalized, self::SENSITIVE_EXACT, true)) {
            return true;
        }

        foreach (self::SENSITIVE as $needle) {
            if ($normalized === $needle || str_contains($normalized, $needle)) {
                return true;
            }
        }

        return false;
    }
}
