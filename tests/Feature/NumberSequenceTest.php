<?php

namespace Tests\Feature;

use App\Models\Core\Company;
use App\Models\Core\NumberSequence;
use App\Models\User;
use App\Services\Core\CompanyService;
use App\Services\Core\NumberSequenceService;
use App\Support\NumberSequences\NumberSequenceDefinition;
use App\Support\NumberSequences\NumberSequenceRegistry;
use App\Support\NumberSequences\NumberTemplate;
use App\Support\NumberSequences\ResetPeriod;
use App\Support\PublicId;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;
use Tests\Traits\CreatesCompanyUser;

class NumberSequenceTest extends TestCase
{
    use CreatesCompanyUser;
    use RefreshDatabase;

    private User $admin;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->createCompanyUser();
        $this->company = $this->admin->company;
        CompanyService::resetCache();
        $this->actingAs($this->admin);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** Freezes the clock at a UTC instant; the company (Asia/Jakarta, +7) sees that instant seven hours later. */
    private function at(string $utc): void
    {
        Carbon::setTestNow(Carbon::parse($utc, 'UTC'));
    }

    private function year(): string
    {
        return now($this->company->timezone)->format('Y');
    }

    /** @param  list<string>  $codes */
    private function insertCustomers(int $companyId, array $codes, bool $trashed = false): void
    {
        $now = now();

        DB::table('customers')->insert(array_map(static fn (string $code): array => [
            'public_id' => PublicId::new(),
            'company_id' => $companyId,
            'code' => $code,
            'name' => $code,
            'type' => 'Individual',
            'is_active' => true,
            'deleted_at' => $trashed ? $now : null,
            'created_at' => $now,
            'updated_at' => $now,
        ], $codes));
    }

    private function probe(string $entityType, string $prefix, string $template, ResetPeriod $reset = ResetPeriod::Never): void
    {
        app(NumberSequenceRegistry::class)->register(
            new NumberSequenceDefinition($entityType, 'Probe '.$entityType, 'customers', 'code', 255, $prefix, $template, $reset),
        );
    }

    private function row(string $entityType, ?Company $company = null): NumberSequence
    {
        return NumberSequence::forCompany($company->id ?? $this->company->id)->where('entity_type', $entityType)->firstOrFail();
    }

    public function test_first_number_generation(): void
    {
        $this->assertSame('INV-'.$this->year().'-00001', NumberSequenceService::generate('invoice'));
    }

    public function test_next_number_increments(): void
    {
        $this->assertSame('INV-'.$this->year().'-00001', NumberSequenceService::generate('invoice'));
        $this->assertSame('INV-'.$this->year().'-00002', NumberSequenceService::generate('invoice'));
        $this->assertSame(3, $this->row('invoice')->next_number);
    }

    public function test_different_sequences_do_not_conflict(): void
    {
        $this->assertSame('INV-'.$this->year().'-00001', NumberSequenceService::generate('invoice'));
        $this->assertSame('SPK-'.$this->year().'-00001', NumberSequenceService::generate('spk'));
        $this->assertSame('SUB-'.$this->year().'-00001', NumberSequenceService::generate('subscription'));
        $this->assertSame('NA-'.$this->year().'-00001', NumberSequenceService::generate('network_asset'));
    }

    public function test_company_scope_isolation(): void
    {
        $otherCompany = Company::factory()->create();

        $this->assertSame('INV-'.$this->year().'-00001', NumberSequenceService::generate('invoice'));
        $this->assertSame('INV-'.$this->year().'-00001', NumberSequenceService::generate('invoice', $otherCompany->id));
        $this->assertSame('INV-'.$this->year().'-00002', NumberSequenceService::generate('invoice'));
        $this->assertSame(2, $this->row('invoice', $otherCompany)->next_number);
    }

    public function test_another_company_taken_codes_do_not_block_this_company(): void
    {
        $otherCompany = Company::factory()->create();
        NumberSequenceService::ensureAll($this->company->id);
        $this->insertCustomers($otherCompany->id, ['CUS-00001', 'CUS-00002']);

        $this->assertSame('CUS-00001', NumberSequenceService::generate('customer'));
    }

    public function test_generate_without_a_company_context_is_refused(): void
    {
        Auth::forgetGuards();

        $this->expectException(HttpException::class);

        NumberSequenceService::generate('invoice');
    }

    public function test_an_unknown_entity_is_an_error_everywhere(): void
    {
        foreach ([
            fn () => NumberSequenceService::generate('quote'),
            fn () => NumberSequenceService::peek('quote'),
            fn () => NumberSequenceService::allowsManual('quote'),
            fn () => NumberSequenceService::resolve('quote', 'X-1'),
            fn () => app(NumberSequenceRegistry::class)->get('quote'),
        ] as $call) {
            try {
                $call();
                $this->fail('An unregistered entity must throw.');
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('quote', $e->getMessage());
            }
        }
    }

    public function test_every_entity_is_registered_with_its_default_format(): void
    {
        $expected = [
            'invoice' => ['Tagihan', 'invoices', 'number', 'INV', '{PREFIX}-{YYYY}-{SEQ:5}', ResetPeriod::Never, false],
            'spk' => ['SPK', 'work_orders', 'code', 'SPK', '{PREFIX}-{YYYY}-{SEQ:5}', ResetPeriod::Never, false],
            'subscription' => ['Langganan', 'service_subscriptions', 'code', 'SUB', '{PREFIX}-{YYYY}-{SEQ:5}', ResetPeriod::Never, false],
            'network_asset' => ['Aset Jaringan', 'network_assets', 'code', 'NA', '{PREFIX}-{YYYY}-{SEQ:5}', ResetPeriod::Never, false],
            'ticket' => ['Tiket', 'tickets', 'code', 'TKT', '{PREFIX}-{YYYY}-{SEQ:5}', ResetPeriod::Yearly, false],
            'inventory_request' => ['Permintaan Barang', 'inventory_requests', 'code', 'REQ', '{PREFIX}-{YYYY}{MM}{DD}-{SEQ:4}', ResetPeriod::Daily, false],
            'customer' => ['Pelanggan', 'customers', 'code', 'CUS', '{PREFIX}-{SEQ:5}', ResetPeriod::Never, false],
            'employee' => ['Karyawan', 'employee_profiles', 'employee_number', 'EMP', '{PREFIX}-{SEQ:4}', ResetPeriod::Never, false],
            'product' => ['Barang (SKU)', 'products', 'sku', 'PRD', '{PREFIX}-{SEQ:5}', ResetPeriod::Never, true],
        ];
        $registry = app(NumberSequenceRegistry::class);

        $this->assertSame($registry, app(NumberSequenceRegistry::class), 'one registry per application');
        $this->assertEqualsCanonicalizing(array_keys($expected), array_keys($registry->all()));

        foreach ($expected as $entityType => [$label, $table, $column, $prefix, $template, $reset, $manual]) {
            $definition = $registry->get($entityType);

            $this->assertSame([$entityType, $label, $table, $column, $prefix, $template, $reset, $manual], [
                $definition->entityType, $definition->label, $definition->table, $definition->column,
                $definition->defaultPrefix, $definition->defaultTemplate, $definition->defaultReset, $definition->defaultAllowManual,
            ], $entityType);
            $this->assertNull(NumberTemplate::validate($definition->defaultTemplate, $definition->defaultReset), $entityType);
        }
    }

    public function test_the_registered_max_length_is_the_real_column_length(): void
    {
        foreach (app(NumberSequenceRegistry::class)->all() as $definition) {
            $column = collect(Schema::getColumns($definition->table))->firstWhere('name', $definition->column);

            $this->assertNotNull($column, "{$definition->table}.{$definition->column} exists");
            $this->assertSame($definition->maxLength, (int) preg_replace('/\D/', '', $column['type']), $definition->entityType);
            $this->assertTrue(Schema::hasColumn($definition->table, 'company_id'), "{$definition->table} is company scoped");

            $longest = NumberTemplate::render($definition->defaultTemplate, $definition->defaultPrefix, str_repeat('C', 50), 10 ** (int) NumberTemplate::seqWidth($definition->defaultTemplate) - 1, now());
            $this->assertLessThanOrEqual($definition->maxLength, mb_strlen($longest), $definition->entityType);
        }
    }

    public function test_peek_returns_next_without_consuming(): void
    {
        NumberSequenceService::generate('invoice');

        $this->assertSame('INV-'.$this->year().'-00002', NumberSequenceService::peek('invoice'));
        $this->assertSame('INV-'.$this->year().'-00002', NumberSequenceService::peek('invoice'));
        $this->assertSame('INV-'.$this->year().'-00002', NumberSequenceService::generate('invoice'));
    }

    public function test_peek_and_generate_agree_for_every_entity(): void
    {
        $this->at('2026-10-13 05:00:00');

        foreach (array_keys(app(NumberSequenceRegistry::class)->all()) as $entityType) {
            $peeked = NumberSequenceService::peek($entityType);

            $this->assertSame($peeked, NumberSequenceService::generate($entityType), $entityType);
            $this->assertNotSame($peeked, NumberSequenceService::peek($entityType), $entityType);
        }
    }

    public function test_peek_of_an_entity_never_used_writes_nothing_and_starts_after_existing_codes(): void
    {
        $this->insertCustomers($this->company->id, ['CUS-00004']);

        $this->assertSame('CUS-00005', NumberSequenceService::peek('customer'));
        $this->assertSame(0, NumberSequence::query()->count());
        $this->assertSame('CUS-00005', NumberSequenceService::generate('customer'));
        $this->assertSame(6, $this->row('customer')->next_number);
    }

    public function test_generate_skips_codes_that_already_exist_soft_deleted_ones_included(): void
    {
        NumberSequenceService::ensureAll($this->company->id);
        $this->insertCustomers($this->company->id, ['CUS-00002']);
        $this->insertCustomers($this->company->id, ['CUS-00003'], trashed: true);

        $this->assertSame('CUS-00001', NumberSequenceService::generate('customer'));
        $this->assertSame('CUS-00004', NumberSequenceService::generate('customer'));
        $this->assertSame(5, $this->row('customer')->next_number);
        $this->assertSame('CUS-00005', NumberSequenceService::peek('customer'));
    }

    public function test_peek_skips_taken_codes_like_generate(): void
    {
        NumberSequenceService::ensureAll($this->company->id);
        $this->insertCustomers($this->company->id, ['CUS-00001', 'CUS-00002']);

        $this->assertSame('CUS-00003', NumberSequenceService::peek('customer'));
        $this->assertSame(1, $this->row('customer')->next_number, 'peek leaves the counter alone');
    }

    public function test_generate_skips_up_to_1000_taken_codes_then_gives_up(): void
    {
        NumberSequenceService::ensureAll($this->company->id);
        $this->insertCustomers($this->company->id, array_map(static fn (int $n): string => sprintf('CUS-%05d', $n), range(1, 1000)));

        $this->assertSame('CUS-01001', NumberSequenceService::generate('customer'));

        $this->insertCustomers($this->company->id, array_map(static fn (int $n): string => sprintf('CUS-%05d', $n), range(1002, 2002)));

        try {
            NumberSequenceService::generate('customer');
            $this->fail('1001 taken codes in a row must stop generation.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Pelanggan', $e->getMessage());
        }

        $this->assertSame(1002, $this->row('customer')->next_number, 'a failed generate does not move the counter');
        $this->assertSame('CUS-01002', NumberSequenceService::peek('customer'), 'the page can still show where the counter stands');
    }

    public function test_generate_takes_the_row_lock_so_parallel_creates_cannot_share_a_number(): void
    {
        DB::enableQueryLog();
        $codes = array_map(static fn (): string => NumberSequenceService::generate('invoice'), range(1, 20));
        $queries = array_column(DB::getQueryLog(), 'query');
        DB::disableQueryLog();

        $this->assertSame(range(1, 20), array_map(static fn (string $code): int => (int) substr($code, -5), $codes));
        $this->assertCount(20, array_unique($codes));
        $locking = array_filter($queries, static fn (string $sql): bool => str_contains($sql, '`number_sequences`') && str_contains($sql, 'for update'));
        $this->assertCount(20, $locking, 'every generate reads its row with FOR UPDATE');
    }

    public function test_a_rolled_back_create_gives_its_number_back(): void
    {
        $this->assertSame('INV-'.$this->year().'-00001', NumberSequenceService::generate('invoice'));

        try {
            DB::transaction(function (): void {
                $this->assertSame('INV-'.$this->year().'-00002', NumberSequenceService::generate('invoice'));

                throw new RuntimeException('create failed after the number was taken');
            });
        } catch (RuntimeException) {
        }

        $this->assertSame('INV-'.$this->year().'-00002', NumberSequenceService::generate('invoice'));
    }

    public function test_a_yearly_sequence_restarts_at_the_new_year_of_the_company(): void
    {
        $this->at('2026-12-31 16:59:59'); // 23:59:59 in Jakarta
        $this->assertSame('TKT-2026-00001', NumberSequenceService::generate('ticket'));
        $this->assertSame('TKT-2026-00002', NumberSequenceService::generate('ticket'));
        $this->assertSame('2026', $this->row('ticket')->period_key);

        $this->at('2026-12-31 17:00:00'); // 00:00:00 on 1 January in Jakarta
        $this->assertSame('TKT-2027-00001', NumberSequenceService::peek('ticket'));
        $this->assertSame('TKT-2027-00001', NumberSequenceService::generate('ticket'));
        $this->assertSame('TKT-2027-00002', NumberSequenceService::generate('ticket'));
        $this->assertSame('2027', $this->row('ticket')->period_key);
    }

    public function test_a_daily_sequence_restarts_at_midnight_of_the_company(): void
    {
        $this->at('2026-10-13 16:59:59');
        $this->assertSame('REQ-20261013-0001', NumberSequenceService::generate('inventory_request'));
        $this->assertSame('REQ-20261013-0002', NumberSequenceService::generate('inventory_request'));

        $this->at('2026-10-13 17:00:00');
        $this->assertSame('REQ-20261014-0001', NumberSequenceService::generate('inventory_request'));
        $this->assertSame('2026-10-14', $this->row('inventory_request')->period_key);
    }

    public function test_a_monthly_sequence_restarts_at_the_new_month(): void
    {
        $this->at('2026-10-31 10:00:00');
        NumberSequenceService::ensureAll($this->company->id);
        NumberSequenceService::update($this->row('invoice'), [
            'prefix' => 'INV', 'template' => '{PREFIX}-{YY}{MM}-{SEQ:3}', 'next_number' => 5, 'reset_period' => 'monthly', 'allow_manual' => false,
        ]);

        $this->at('2026-10-31 16:59:59');
        $this->assertSame('INV-2610-005', NumberSequenceService::generate('invoice'));
        $this->assertSame('INV-2610-006', NumberSequenceService::generate('invoice'));

        $this->at('2026-10-31 17:00:00');
        $this->assertSame('INV-2611-001', NumberSequenceService::generate('invoice'));
        $this->assertSame('2026-11', $this->row('invoice')->period_key);
    }

    public function test_a_sequence_that_never_resets_keeps_counting_across_years(): void
    {
        $this->at('2026-12-31 16:59:59');
        $this->assertSame('INV-2026-00001', NumberSequenceService::generate('invoice'));

        $this->at('2027-01-01 03:00:00');
        $this->assertSame('INV-2027-00002', NumberSequenceService::generate('invoice'));
        $this->assertNull($this->row('invoice')->period_key);
    }

    public function test_a_row_without_a_period_key_joins_the_running_period_and_a_stale_key_restarts_it(): void
    {
        $this->at('2026-10-13 05:00:00');
        NumberSequence::factory()->create([
            'company_id' => $this->company->id, 'entity_type' => 'ticket', 'prefix' => 'TKT', 'template' => '{PREFIX}-{YYYY}-{SEQ:5}',
            'next_number' => 7, 'reset_period' => ResetPeriod::Yearly, 'period_key' => null,
        ]);

        $this->assertSame('TKT-2026-00007', NumberSequenceService::generate('ticket'));
        $this->assertSame('2026', $this->row('ticket')->period_key);

        $this->row('ticket')->update(['period_key' => '2025', 'next_number' => 90]);

        $this->assertSame('TKT-2026-00001', NumberSequenceService::generate('ticket'));
    }

    public function test_date_tokens_follow_the_company_timezone_and_fall_back_to_the_app_timezone(): void
    {
        $this->at('2026-12-31 20:00:00'); // 03:00 on 1 January in Jakarta, still 31 December in UTC

        $this->assertSame('INV-2027-00001', NumberSequenceService::generate('invoice'));

        $this->company->update(['timezone' => 'America/New_York']);
        $this->assertSame('INV-2026-00002', NumberSequenceService::generate('invoice'));

        $this->company->update(['timezone' => '']);
        $this->assertSame('INV-2026-00003', NumberSequenceService::generate('invoice'), 'a blank timezone means the app timezone (UTC)');
    }

    public function test_the_company_token_is_the_company_code(): void
    {
        NumberSequenceService::ensureAll($this->company->id);
        $this->row('customer')->update(['template' => '{COMPANY}/{SEQ:3}']);

        $this->assertSame($this->company->code.'/001', NumberSequenceService::generate('customer'));
    }

    public function test_a_new_row_starts_after_the_highest_code_that_has_the_format(): void
    {
        $otherCompany = Company::factory()->create();
        $this->insertCustomers($this->company->id, ['CUS-00007', 'CUS-00012', 'CUS-1', 'LEGACY-9', 'XYZ-00099', 'CUS-00020-OLD', 'cus-00500']);
        $this->insertCustomers($otherCompany->id, ['CUS-00900']);

        NumberSequenceService::ensureAll($this->company->id);

        $this->assertSame(13, $this->row('customer')->next_number);
        $this->assertSame('CUS-00013', NumberSequenceService::generate('customer'));
        $this->assertSame(1, $this->row('employee')->next_number, 'a table without codes starts at 1');
    }

    public function test_a_new_row_only_counts_codes_of_the_current_period(): void
    {
        $this->at('2026-10-13 05:00:00');
        $this->probe('probe_never', 'PRN', '{PREFIX}-{SEQ:5}');
        $this->probe('probe_never_year', 'PNY', '{PREFIX}-{YYYY}-{SEQ:3}');
        $this->probe('probe_yearly', 'PRY', '{PREFIX}-{YYYY}-{SEQ:4}', ResetPeriod::Yearly);
        $this->probe('probe_yearly_month', 'PYM', '{PREFIX}-{YYYY}{MM}-{SEQ:3}', ResetPeriod::Yearly);
        $this->probe('probe_monthly', 'PRM', '{PREFIX}{YY}{MM}-{SEQ:3}', ResetPeriod::Monthly);
        $this->probe('probe_daily', 'PRD', '{PREFIX}-{YYYY}{MM}{DD}-{SEQ:2}', ResetPeriod::Daily);
        $this->insertCustomers($this->company->id, [
            'PRN-00007', 'PRN-00012',
            'PNY-2025-120', 'PNY-2026-005',
            'PRY-2026-0003', 'PRY-2025-0900', 'PRY-2026-0010',
            'PYM-202601-040', 'PYM-202610-007', 'PYM-202501-999',
            'PRM2610-007', 'PRM2609-050',
            'PRD-20261013-05', 'PRD-20261012-99',
        ]);

        NumberSequenceService::ensureAll($this->company->id);

        $this->assertSame([13, null], $this->pair('probe_never'), 'no reset: every code of the format counts');
        $this->assertSame([121, null], $this->pair('probe_never_year'), 'no reset: the year of a code does not matter');
        $this->assertSame([11, '2026'], $this->pair('probe_yearly'));
        $this->assertSame([41, '2026'], $this->pair('probe_yearly_month'), 'a yearly counter runs through the months');
        $this->assertSame([8, '2026-10'], $this->pair('probe_monthly'));
        $this->assertSame([6, '2026-10-13'], $this->pair('probe_daily'));
    }

    /** @return array{0: int, 1: string|null} */
    private function pair(string $entityType): array
    {
        $row = $this->row($entityType);

        return [$row->next_number, $row->period_key];
    }

    public function test_creating_a_row_never_fails_on_codes_or_templates_it_cannot_read(): void
    {
        $this->probe('probe_no_seq', 'NOS', '{PREFIX}');
        $this->probe('probe_broken', 'BRK', '{NOPE}-{SEQ:0}-{SEQ:99}');
        $this->insertCustomers($this->company->id, ['NOS', 'NOS-00005', '{NOPE}-7', 'ÄÖÜ ??', str_repeat('Z', 200), 'BRK-0000000009']);

        NumberSequenceService::ensureAll($this->company->id);

        $this->assertSame(1, $this->row('probe_no_seq')->next_number);
        $this->assertSame(1, NumberSequence::query()->where('entity_type', 'probe_broken')->value('next_number'));
    }

    public function test_ensure_all_creates_every_missing_row_once_per_company(): void
    {
        $otherCompany = Company::factory()->create();
        $this->at('2026-10-13 05:00:00');

        NumberSequenceService::ensureAll($this->company->id);
        NumberSequenceService::ensureAll($this->company->id);

        $this->assertSame(9, NumberSequence::query()->count());
        $this->assertSame(0, NumberSequence::forCompany($otherCompany->id)->count());
        $this->assertEqualsCanonicalizing(array_keys(app(NumberSequenceRegistry::class)->all()), NumberSequence::query()->pluck('entity_type')->all());

        $ticket = $this->row('ticket');
        $this->assertSame(['TKT', '{PREFIX}-{YYYY}-{SEQ:5}', ResetPeriod::Yearly, '2026', 1, false], [$ticket->prefix, $ticket->template, $ticket->reset_period, $ticket->period_key, $ticket->next_number, $ticket->allow_manual]);
        $product = $this->row('product');
        $this->assertTrue($product->allow_manual);
        $this->assertFalse($this->row('customer')->allow_manual);
        $this->assertSame('2026-10-13', $this->row('inventory_request')->period_key);
        $this->assertNull($this->row('invoice')->period_key);

        NumberSequenceService::ensureAll($otherCompany->id);
        $this->assertSame(9, NumberSequence::forCompany($otherCompany->id)->count());
    }

    public function test_ensure_all_keeps_the_rows_that_exist(): void
    {
        NumberSequenceService::generate('invoice');
        $this->row('invoice')->update(['prefix' => 'FAK', 'template' => '{PREFIX}/{SEQ:3}', 'next_number' => 40]);

        NumberSequenceService::ensureAll($this->company->id);

        $row = $this->row('invoice');
        $this->assertSame(['FAK', '{PREFIX}/{SEQ:3}', 40], [$row->prefix, $row->template, $row->next_number]);
    }

    public function test_manual_input_defaults_follow_the_definition_until_the_row_says_otherwise(): void
    {
        $this->assertTrue(NumberSequenceService::allowsManual('product'));
        $this->assertFalse(NumberSequenceService::allowsManual('customer'));
        $this->assertFalse(NumberSequenceService::allowsManual('employee'));
        $this->assertSame(0, NumberSequence::query()->count(), 'asking creates nothing');

        NumberSequenceService::ensureAll($this->company->id);
        $this->row('customer')->update(['allow_manual' => true]);
        $this->row('product')->update(['allow_manual' => false]);

        $this->assertTrue(NumberSequenceService::allowsManual('customer'));
        $this->assertFalse(NumberSequenceService::allowsManual('product'));
        $this->assertTrue(NumberSequenceService::allowsManual('product', Company::factory()->create()->id), 'a company without a row gets the default');
    }

    public function test_resolve_generates_when_nothing_is_typed(): void
    {
        $this->assertSame('CUS-00001', NumberSequenceService::resolve('customer', null));
        $this->assertSame('CUS-00002', NumberSequenceService::resolve('customer', ''));
        $this->assertSame('CUS-00003', NumberSequenceService::resolve('customer', "  \t "));
        $this->assertSame('PRD-00001', NumberSequenceService::resolve('product', null));
    }

    public function test_resolve_keeps_a_typed_code_only_where_manual_input_is_on(): void
    {
        $this->assertSame('SKU-ABC-1', NumberSequenceService::resolve('product', '  SKU-ABC-1 '));
        $this->assertSame('PRD-00001', NumberSequenceService::generate('product'), 'a typed SKU does not use a number');

        foreach ([['customer', 'code'], ['employee', 'employee_number']] as [$entityType, $field]) {
            try {
                NumberSequenceService::resolve($entityType, 'TYPED-1', $field);
                $this->fail("{$entityType} must refuse a typed code.");
            } catch (ValidationException $e) {
                $this->assertSame([$field], array_keys($e->errors()), $entityType);
                $this->assertSame('Kolom ini dibuat otomatis oleh sistem dan tidak dapat diisi manual.', $e->errors()[$field][0]);
            }
        }

        $this->assertSame(0, NumberSequence::query()->whereIn('entity_type', ['customer', 'employee'])->count(), 'a refused code consumes nothing');
    }

    public function test_resolve_follows_the_switch_on_the_row(): void
    {
        NumberSequenceService::ensureAll($this->company->id);
        $this->row('customer')->update(['allow_manual' => true]);
        $this->row('product')->update(['allow_manual' => false]);

        $this->assertSame('MY-CUST-1', NumberSequenceService::resolve('customer', 'MY-CUST-1'));
        $this->expectException(ValidationException::class);

        NumberSequenceService::resolve('product', 'SKU-1', 'sku');
    }
}
