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
    ];

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public static function redact(array $context): array
    {
        $out = [];

        foreach ($context as $key => $value) {
            if (is_array($value)) {
                $out[$key] = self::redact($value);

                continue;
            }

            $out[$key] = self::isSensitive((string) $key) ? '[REDACTED]' : $value;
        }

        return $out;
    }

    private static function isSensitive(string $key): bool
    {
        $normalized = strtolower($key);

        foreach (self::SENSITIVE as $needle) {
            if ($normalized === $needle || str_contains($normalized, $needle)) {
                return true;
            }
        }

        return false;
    }
}
