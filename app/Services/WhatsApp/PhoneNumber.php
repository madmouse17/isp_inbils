<?php

namespace App\Services\WhatsApp;

/**
 * Send-time phone cleanup for WhatsApp. Stored phones are never rewritten.
 */
final class PhoneNumber
{
    /**
     * Indonesian mobile numbers only, as the digits Meta wants (62…). Landlines, foreign numbers, a bare
     * 812… without prefix and garbage give null: such a recipient is skipped, never guessed at.
     */
    public static function normalizeIndonesianMobile(#[\SensitiveParameter] ?string $phone): ?string
    {
        // Spaces, ().- and the invisible format marks that WhatsApp adds when a number is copied from it.
        $digits = preg_replace('/[\s\p{Z}\p{Cf}().-]+/u', '', (string) $phone);

        if ($digits === null) {
            return null;
        }

        if (str_starts_with($digits, '+62')) {
            $digits = '62'.substr($digits, 3);
        } elseif (str_starts_with($digits, '0062')) {
            $digits = '62'.substr($digits, 4);
        } elseif (str_starts_with($digits, '0')) {
            $digits = '62'.substr($digits, 1);
        }

        return preg_match('/^628[1-9][0-9]{6,10}$/', $digits) === 1 ? $digits : null;
    }
}
