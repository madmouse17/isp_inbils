<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Models\Core\Company;
use App\Models\Core\NumberSequence;
use App\Services\Core\NumberSequenceService;
use App\Support\NumberSequences\ResetPeriod;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * M-4 (K29): codes of a request for goods read REQ-2026-00003 like the SPK and ticket codes, and the counter restarts each
 * year. A company that still holds the first format (REQ-20261020-0003, restarting each day) is moved; one that changed
 * its format or its reset period on the Penomoran page is left alone, counter included.
 */
class RequestNumberYearlyTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'Modules/Inventory/database/migrations/2026_11_02_100400_move_inventory_request_numbers_to_yearly.php';

    private const OLD = '{PREFIX}-{YYYY}{MM}{DD}-{SEQ:4}';

    private const NEW = '{PREFIX}-{YYYY}-{SEQ:5}';

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_a_company_made_after_the_change_starts_on_the_yearly_format(): void
    {
        $company = Company::factory()->create();
        Carbon::setTestNow(Carbon::parse('2026-12-31 16:59:59', 'UTC')); // 23:59:59 in Jakarta

        $this->assertSame('REQ-2026-00001', NumberSequenceService::generate('inventory_request', $company->id));
        $this->assertSame('REQ-2026-00002', NumberSequenceService::generate('inventory_request', $company->id));

        $row = $this->row($company);
        $this->assertSame([self::NEW, ResetPeriod::Yearly, '2026', 3], [$row->template, $row->reset_period, $row->period_key, $row->next_number]);

        Carbon::setTestNow(Carbon::parse('2026-12-31 17:00:00', 'UTC')); // 1 January in Jakarta
        $this->assertSame('REQ-2027-00001', NumberSequenceService::generate('inventory_request', $company->id));
    }

    public function test_the_migration_moves_every_company_still_on_the_first_format_and_only_those(): void
    {
        $first = $this->legacyRow(Company::factory()->create(), next: 7, periodKey: '2026-10-13');
        $second = $this->legacyRow(Company::factory()->create(), next: 3, periodKey: '2026-10-13', prefix: 'PMT');
        $ownTemplate = Company::factory()->create();
        $neverResets = Company::factory()->create();
        $this->legacyRow($ownTemplate, next: 9, periodKey: '2026-10-13', template: '{PREFIX}/{YYYY}/{SEQ:3}', reset: ResetPeriod::Yearly);
        $this->legacyRow($neverResets, next: 4, periodKey: null, reset: ResetPeriod::Never);
        $columns = ['prefix', 'template', 'reset_period', 'period_key', 'next_number', 'allow_manual'];
        $kept = [$ownTemplate, $neverResets];
        $before = array_map(fn (Company $company): array => $this->row($company)->only($columns), $kept);

        (require base_path(self::MIGRATION))->up();

        foreach ([[$first, 'REQ'], [$second, 'PMT']] as [$row, $prefix]) {
            $now = $this->row($row->company);
            $this->assertSame([$prefix, self::NEW, ResetPeriod::Yearly, null, 1], [$now->prefix, $now->template, $now->reset_period, $now->period_key, $now->next_number], 'the prefix stays, the format and the counter change');
        }
        $this->assertSame($before, array_map(fn (Company $company): array => $this->row($company)->only($columns), $kept), 'a company that changed its format or its reset keeps both, and its counter');
    }

    public function test_a_moved_company_numbers_by_the_year_from_one(): void
    {
        $company = Company::factory()->create();
        $this->legacyRow($company, next: 27, periodKey: '2026-10-13');
        Carbon::setTestNow(Carbon::parse('2026-10-20 03:00:00', 'UTC'));

        (require base_path(self::MIGRATION))->up();

        $this->assertSame('REQ-2026-00001', NumberSequenceService::generate('inventory_request', $company->id));
        $this->assertSame('REQ-2026-00002', NumberSequenceService::generate('inventory_request', $company->id));
        $this->assertSame('2026', $this->row($company)->period_key);
    }

    public function test_running_it_twice_changes_nothing_the_second_time(): void
    {
        $company = Company::factory()->create();
        $this->legacyRow($company, next: 5, periodKey: '2026-10-13');
        $migration = require base_path(self::MIGRATION);
        $migration->up();
        Carbon::setTestNow(Carbon::parse('2026-10-20 03:00:00', 'UTC'));
        NumberSequenceService::generate('inventory_request', $company->id);

        $migration->up();

        $this->assertSame(2, $this->row($company)->next_number, 'the counter was not put back to 1');
    }

    public function test_taking_it_back_restores_the_first_format_for_rows_on_the_new_pair_only(): void
    {
        $moved = Company::factory()->create();
        $ownTemplate = Company::factory()->create();
        $this->legacyRow($moved, next: 7, periodKey: '2026-10-13');
        $this->legacyRow($ownTemplate, next: 4, periodKey: '2026', template: '{PREFIX}/{YYYY}/{SEQ:3}', reset: ResetPeriod::Yearly);
        $migration = require base_path(self::MIGRATION);
        $migration->up();

        $migration->down();

        $row = $this->row($moved);
        $this->assertSame([self::OLD, ResetPeriod::Daily, null, 1], [$row->template, $row->reset_period, $row->period_key, $row->next_number]);
        $kept = $this->row($ownTemplate);
        $this->assertSame(['{PREFIX}/{YYYY}/{SEQ:3}', ResetPeriod::Yearly, '2026', 4], [$kept->template, $kept->reset_period, $kept->period_key, $kept->next_number]);
    }

    private function row(Company $company): NumberSequence
    {
        return NumberSequence::forCompany($company->id)->where('entity_type', 'inventory_request')->firstOrFail();
    }

    /** The row a company got when the first format was the default. */
    private function legacyRow(Company $company, int $next, ?string $periodKey, string $template = self::OLD, ResetPeriod $reset = ResetPeriod::Daily, string $prefix = 'REQ'): NumberSequence
    {
        return NumberSequence::forCompany($company->id)->create([
            'company_id' => $company->id,
            'entity_type' => 'inventory_request',
            'prefix' => $prefix,
            'template' => $template,
            'next_number' => $next,
            'reset_period' => $reset,
            'period_key' => $periodKey,
            'allow_manual' => false,
        ]);
    }
}
