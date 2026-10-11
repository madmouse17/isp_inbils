<?php

namespace Tests\Unit\WhatsApp;

use App\Services\WhatsApp\PhoneNumber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PhoneNumberTest extends TestCase
{
    /** @return array<string, array{0: ?string, 1: ?string}> */
    public static function phones(): array
    {
        return [
            'local with zero' => ['081234567890', '6281234567890'],
            'local with hyphens' => ['0812-3456-7890', '6281234567890'],
            'plus 62 with spaces and hyphens' => ['+62 812-3456-7890', '6281234567890'],
            'bare 62' => ['6281234567890', '6281234567890'],
            '62 with spaces' => ['62812 3456 7890', '6281234567890'],
            '0062 prefix' => ['0062 812 3456 7890', '6281234567890'],
            'parentheses and dots' => ['(0812) 3456.7890', '6281234567890'],
            'surrounding whitespace' => [' 0812 3456 7890 ', '6281234567890'],
            'pasted from WhatsApp with direction marks and nbsp' => ["\u{202A}+62\u{00A0}812-3456-7890\u{202C}", '6281234567890'],
            'typical eleven digit mobile' => ['08123456789', '628123456789'],
            'shortest valid mobile' => ['081234567', '6281234567'],
            'longest valid mobile' => ['0812345678901', '62812345678901'],

            'bare national number without prefix is not guessed' => ['812-3456-7890', null],
            'landline' => ['021-5551234', null],
            'foreign +65' => ['+65 9123 4567', null],
            'foreign +1' => ['+1 650 555 1234', null],
            'empty' => ['', null],
            'null' => [null, null],
            'only separators' => [' - () ', null],
            'garbage' => ['abc', null],
            'letters inside' => ['0812abc4567890', null],
            'plus inside' => ['0812+3456789', null],
            'too short' => ['08123456', null],
            'too long' => ['08123456789012', null],
            'second digit zero' => ['0801234567890', null],
            'trunk zero in parentheses is not supported' => ['+62 (0)812 3456 7890', null],
        ];
    }

    #[DataProvider('phones')]
    public function test_normalizes_indonesian_mobile_numbers(?string $input, ?string $expected): void
    {
        $this->assertSame($expected, PhoneNumber::normalizeIndonesianMobile($input));
    }
}
