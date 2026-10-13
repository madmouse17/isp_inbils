<?php

namespace Tests\Unit;

use App\Support\NumberSequences\NumberTemplate;
use App\Support\NumberSequences\ResetPeriod;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

/** The cases mirror resources/js/lib/numberTemplate.selfcheck.mjs: the page previews with the same grammar. */
class NumberTemplateTest extends TestCase
{
    private function render(string $template, int $number = 7, string $prefix = 'INV'): string
    {
        return NumberTemplate::render($template, $prefix, 'PST', $number, CarbonImmutable::create(2026, 10, 3, 14, 30));
    }

    public function test_render_fills_every_token(): void
    {
        $this->assertSame('INV', $this->render('{PREFIX}'));
        $this->assertSame('2026', $this->render('{YYYY}'));
        $this->assertSame('26', $this->render('{YY}'));
        $this->assertSame('10', $this->render('{MM}'));
        $this->assertSame('03', $this->render('{DD}'));
        $this->assertSame('PST', $this->render('{COMPANY}'));
        $this->assertSame('7', $this->render('{SEQ:1}'));
        $this->assertSame('00007', $this->render('{SEQ:5}'));
        $this->assertSame('000007', $this->render('{SEQ:6}'));
        $this->assertSame('0000000007', $this->render('{SEQ:10}'));
        $this->assertSame('INV-2026-00007', $this->render('{PREFIX}-{YYYY}-{SEQ:5}'));
        $this->assertSame('INV-20261003-0007', $this->render('{PREFIX}-{YYYY}{MM}{DD}-{SEQ:4}'));
        $this->assertSame('PST/26/10/007.x_1', $this->render('{COMPANY}/{YY}/{MM}/{SEQ:3}.x_1'));
    }

    public function test_render_never_cuts_the_number_and_keeps_unknown_tokens(): void
    {
        $this->assertSame('12345', $this->render('{SEQ:2}', 12345));
        $this->assertSame('-00007', $this->render('{PREFIX}-{SEQ:5}', 7, ''));
        $this->assertSame('{BOGUS}-007', $this->render('{BOGUS}-{SEQ:3}'));
        $this->assertSame('{YYYY:3}-007', $this->render('{YYYY:3}-{SEQ:3}'));
        $this->assertSame('{SEQ}', $this->render('{SEQ}'));
    }

    public function test_validate_accepts_the_default_formats(): void
    {
        $this->assertNull(NumberTemplate::validate('{PREFIX}-{YYYY}-{SEQ:5}', ResetPeriod::Yearly));
        $this->assertNull(NumberTemplate::validate('{PREFIX}-{SEQ:5}', ResetPeriod::Never));
        $this->assertNull(NumberTemplate::validate('{PREFIX}-{SEQ:6}', ResetPeriod::Never));
        $this->assertNull(NumberTemplate::validate('{PREFIX}-{SEQ:10}', ResetPeriod::Never));
        $this->assertNull(NumberTemplate::validate('{PREFIX}-{YY}{MM}-{SEQ:4}', ResetPeriod::Monthly));
        $this->assertNull(NumberTemplate::validate('{PREFIX}-{YYYY}{MM}{DD}-{SEQ:4}', ResetPeriod::Daily));
        $this->assertNull(NumberTemplate::validate('{COMPANY}/{YY}/{MM}/{SEQ:3}.x_1', ResetPeriod::Monthly));
    }

    public function test_validate_needs_exactly_one_sequence_token_of_1_to_10_digits(): void
    {
        $message = 'Format harus memuat tepat satu {SEQ:n}, n antara 1 dan 10.';

        $this->assertSame($message, NumberTemplate::validate('{PREFIX}', ResetPeriod::Never));
        $this->assertSame($message, NumberTemplate::validate('', ResetPeriod::Never));
        $this->assertSame($message, NumberTemplate::validate('{SEQ:3}-{SEQ:3}', ResetPeriod::Never));
    }

    public function test_validate_rejects_unknown_or_malformed_tokens(): void
    {
        $this->assertSame('Token {SEQ:0} tidak dikenal.', NumberTemplate::validate('{SEQ:0}', ResetPeriod::Never));
        $this->assertSame('Token {SEQ:11} tidak dikenal.', NumberTemplate::validate('{SEQ:11}', ResetPeriod::Never));
        $this->assertSame('Token {SEQ} tidak dikenal.', NumberTemplate::validate('{SEQ}', ResetPeriod::Never));
        $this->assertSame('Token {NOPE} tidak dikenal.', NumberTemplate::validate('{NOPE}-{SEQ:3}', ResetPeriod::Never));
        $this->assertSame('Token {YYYY:4} tidak dikenal.', NumberTemplate::validate('{YYYY:4}-{SEQ:3}', ResetPeriod::Never));
    }

    public function test_validate_allows_only_the_literal_character_set(): void
    {
        $message = 'Gunakan huruf, angka, tanda - _ / . dan token di dalam kurung kurawal.';

        $this->assertSame($message, NumberTemplate::validate('A B-{SEQ:3}', ResetPeriod::Never));
        $this->assertSame($message, NumberTemplate::validate('{prefix}-{SEQ:3}', ResetPeriod::Never));
        $this->assertSame($message, NumberTemplate::validate('{SEQ:3}#', ResetPeriod::Never));
        $this->assertSame($message, NumberTemplate::validate("{SEQ:3}\n", ResetPeriod::Never), 'a trailing newline is not a literal');
        $this->assertSame($message, NumberTemplate::validate('{PREFIX', ResetPeriod::Never));
    }

    public function test_validate_requires_the_date_tokens_of_the_reset_period(): void
    {
        $this->assertSame('Reset berkala memerlukan {YYYY} atau {YY}.', NumberTemplate::validate('{PREFIX}-{SEQ:5}', ResetPeriod::Yearly));
        $this->assertSame('Reset berkala memerlukan {YYYY} atau {YY}.', NumberTemplate::validate('{MM}-{SEQ:5}', ResetPeriod::Monthly));
        $this->assertSame('Reset bulanan atau harian memerlukan {MM}.', NumberTemplate::validate('{YYYY}-{SEQ:5}', ResetPeriod::Monthly));
        $this->assertSame('Reset bulanan atau harian memerlukan {MM}.', NumberTemplate::validate('{YYYY}-{SEQ:5}', ResetPeriod::Daily));
        $this->assertSame('Reset harian memerlukan {DD}.', NumberTemplate::validate('{YYYY}{MM}-{SEQ:5}', ResetPeriod::Daily));
        $this->assertNull(NumberTemplate::validate('{YY}-{SEQ:5}', ResetPeriod::Yearly), 'a 2-digit year is enough');
    }

    public function test_seq_width_reads_the_digits_of_the_token(): void
    {
        $this->assertSame(6, NumberTemplate::seqWidth('{PREFIX}-{SEQ:6}'));
        $this->assertNull(NumberTemplate::seqWidth('{PREFIX}'));
    }

    public function test_the_token_list_names_every_token_the_validator_accepts(): void
    {
        $tokens = array_column(NumberTemplate::tokens(), 'token');

        $this->assertSame(['{PREFIX}', '{YYYY}', '{YY}', '{MM}', '{DD}', '{COMPANY}', '{SEQ:5}'], $tokens);
        $this->assertNull(NumberTemplate::validate(implode('-', $tokens), ResetPeriod::Never));
    }

    private function parse(string $template, string $code, ResetPeriod $reset): ?int
    {
        return NumberTemplate::parse($template, $code, 'INV', 'PST', $reset, CarbonImmutable::create(2026, 10, 3, 14, 30));
    }

    public function test_parse_reads_the_number_of_a_code_that_has_the_format(): void
    {
        $this->assertSame(7, $this->parse('{PREFIX}-{YYYY}-{SEQ:5}', 'INV-2026-00007', ResetPeriod::Yearly));
        $this->assertSame(12345, $this->parse('{PREFIX}-{YYYY}-{SEQ:2}', 'INV-2026-12345', ResetPeriod::Yearly), 'a number longer than its padding');
        $this->assertSame(42, $this->parse('{COMPANY}/{YY}/{MM}/{SEQ:3}.x_1', 'PST/26/10/042.x_1', ResetPeriod::Monthly));
        $this->assertSame(5, $this->parse('{SEQ:2}{YY}', '0526', ResetPeriod::Yearly), 'a sequence in front of a date part');
    }

    public function test_parse_ignores_codes_that_do_not_have_the_format(): void
    {
        $this->assertNull($this->parse('{PREFIX}-{SEQ:5}', 'INV-7', ResetPeriod::Never), 'shorter than the padding');
        $this->assertNull($this->parse('{PREFIX}-{SEQ:5}', 'XYZ-00007', ResetPeriod::Never), 'other prefix');
        $this->assertNull($this->parse('{PREFIX}-{SEQ:5}', 'INV-00007-OLD', ResetPeriod::Never), 'trailing text');
        $this->assertNull($this->parse('{PREFIX}-{SEQ:5}', "INV-00007\n", ResetPeriod::Never), 'trailing newline');
        $this->assertNull($this->parse('{PREFIX}-{SEQ:5}', 'inv-00007', ResetPeriod::Never), 'case differs');
        $this->assertNull($this->parse('{PREFIX}-{SEQ:5}', 'INV-0000A', ResetPeriod::Never));
        $this->assertNull($this->parse('{PREFIX}-{SEQ:5}', 'FREE FORM LEGACY', ResetPeriod::Never));
        $this->assertNull($this->parse('{PREFIX}-{SEQ:5}', '', ResetPeriod::Never));
    }

    public function test_parse_never_throws_on_a_template_it_cannot_use(): void
    {
        $this->assertNull($this->parse('{PREFIX}', 'INV', ResetPeriod::Never), 'no sequence token');
        $this->assertNull($this->parse('', 'INV-00007', ResetPeriod::Never));
        $this->assertNull($this->parse('{PREFIX}-{NOPE}-{SEQ:5}', 'INV-X-00007', ResetPeriod::Never), 'unknown tokens are literal text');
        $this->assertSame(7, $this->parse('{PREFIX}-{NOPE}-{SEQ:5}', 'INV-{NOPE}-00007', ResetPeriod::Never));
        $this->assertSame(7, $this->parse('{SEQ:0}', '7', ResetPeriod::Never));
        $this->assertSame(7, $this->parse('{SEQ:50}', '0000000007', ResetPeriod::Never), 'a width past 10 is capped, not a broken pattern');
        $this->assertSame(7, $this->parse('{PREFIX}-{SEQ:5}-{SEQ:5}', 'INV-00007-00009', ResetPeriod::Never), 'the first sequence token wins');
    }

    public function test_parse_quotes_the_prefix_and_company_code(): void
    {
        $this->assertSame(
            9,
            NumberTemplate::parse('{PREFIX}/{COMPANY}-{SEQ:3}', 'P.R(X)/A+B-009', 'P.R(X)', 'A+B', ResetPeriod::Never, CarbonImmutable::create(2026, 10, 3)),
        );
        $this->assertNull(NumberTemplate::parse('{PREFIX}-{SEQ:3}', 'PxR-009', 'P.R', 'PST', ResetPeriod::Never, CarbonImmutable::create(2026, 10, 3)));
    }

    public function test_parse_fixes_the_date_parts_that_identify_the_period(): void
    {
        $daily = '{PREFIX}-{YYYY}{MM}{DD}-{SEQ:2}';
        $this->assertSame(5, $this->parse($daily, 'INV-20261003-05', ResetPeriod::Daily));
        $this->assertNull($this->parse($daily, 'INV-20261002-05', ResetPeriod::Daily), 'yesterday');
        $this->assertNull($this->parse($daily, 'INV-20250103-05', ResetPeriod::Daily), 'last year');

        $monthly = '{PREFIX}{YY}{MM}-{SEQ:3}';
        $this->assertSame(8, $this->parse($monthly, 'INV2610-008', ResetPeriod::Monthly));
        $this->assertNull($this->parse($monthly, 'INV2609-008', ResetPeriod::Monthly), 'last month');

        $yearly = '{PREFIX}-{YYYY}-{SEQ:4}';
        $this->assertSame(3, $this->parse($yearly, 'INV-2026-0003', ResetPeriod::Yearly));
        $this->assertNull($this->parse($yearly, 'INV-2025-0003', ResetPeriod::Yearly), 'last year');
    }

    public function test_parse_lets_date_parts_finer_than_the_reset_period_take_any_value(): void
    {
        // The counter runs through the months of a yearly reset and through all dates when it never resets.
        $this->assertSame(40, $this->parse('{PREFIX}-{YYYY}{MM}-{SEQ:3}', 'INV-202601-040', ResetPeriod::Yearly));
        $this->assertNull($this->parse('{PREFIX}-{YYYY}{MM}-{SEQ:3}', 'INV-202501-040', ResetPeriod::Yearly), 'the year still has to match');
        $this->assertSame(120, $this->parse('{PREFIX}-{YYYY}-{SEQ:3}', 'INV-2025-120', ResetPeriod::Never));
        $this->assertNull($this->parse('{PREFIX}-{YYYY}-{SEQ:3}', 'INV-25-120', ResetPeriod::Never), 'the year keeps its width');
    }

    public function test_reset_period_keys_change_exactly_when_the_period_rolls_over(): void
    {
        $at = CarbonImmutable::create(2026, 10, 3, 23, 59, 59);

        $this->assertNull(ResetPeriod::Never->key($at));
        $this->assertSame('2026', ResetPeriod::Yearly->key($at));
        $this->assertSame('2026-10', ResetPeriod::Monthly->key($at));
        $this->assertSame('2026-10-03', ResetPeriod::Daily->key($at));
        $this->assertSame('2026-10-04', ResetPeriod::Daily->key($at->addSecond()));
        $this->assertSame('2026-11', ResetPeriod::Monthly->key($at->endOfMonth()->addSecond()));
        $this->assertSame('2027', ResetPeriod::Yearly->key($at->endOfYear()->addSecond()));
        $this->assertSame([0, 1, 2, 3], array_map(static fn (ResetPeriod $period): int => $period->dateParts(), ResetPeriod::cases()));
    }
}
