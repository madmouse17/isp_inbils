<?php

namespace Tests\Feature\Billing;

use App\Models\Core\Company;
use App\Models\Core\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Queries\ReceivableInvoicesQuery;
use Modules\Billing\Services\BillingService;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;
use Tests\Traits\AsksInertiaForProps;
use Tests\Traits\CreatesCompanyUser;

/**
 * F5-U03 and K33: Tunggakan lists the open invoices one by one, oldest due date first, with a filter, an age and a file.
 * Time stands at midday of 21 October 2026 in Jakarta, so the company's day and the server's agree.
 */
class ReceivableInvoicesTest extends TestCase
{
    use AsksInertiaForProps;
    use CreatesCompanyUser;
    use RefreshDatabase;

    private const TODAY = '2026-10-21';

    private User $admin;

    private Customer $budi;

    private Customer $sari;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->travelTo('2026-10-21 05:00:00');
        $this->admin = $this->createCompanyUser();
        $this->budi = Customer::factory()->create(['company_id' => $this->admin->company_id, 'name' => 'Budi Santoso', 'code' => 'CUST-BUDI']);
        $this->sari = Customer::factory()->create(['company_id' => $this->admin->company_id, 'name' => 'Sari Wulandari', 'code' => 'CUST-SARI']);
    }

    /** @param array<string, mixed> $attributes */
    private function invoice(string $number, string $dueDate, array $attributes = []): Invoice
    {
        return Invoice::factory()->create([
            'company_id' => $this->admin->company_id,
            'customer_id' => $this->budi->id,
            'number' => $number,
            'status' => 'sent',
            'issue_date' => '2026-06-01',
            'due_date' => $dueDate,
            'total' => 100000,
            'paid_amount' => 0,
            ...$attributes,
        ]);
    }

    /** The mix the other tests read: five open invoices, and four that are not. */
    private function seedOpenAndClosed(): void
    {
        $this->invoice('INV-A', '2026-11-02');
        $this->invoice('INV-B', self::TODAY, ['customer_id' => $this->sari->id]);
        $this->invoice('INV-C', '2026-10-11', ['total' => 200000]);
        $this->invoice('INV-D', '2026-09-01', ['status' => 'partial', 'total' => 250000, 'paid_amount' => 100000, 'customer_id' => $this->sari->id]);
        $this->invoice('INV-E', '2026-07-01', ['status' => 'overdue']);
        $this->invoice('INV-PAID', '2026-10-01', ['status' => 'paid', 'paid_amount' => 100000]);
        $this->invoice('INV-DRAFT', '2026-10-01', ['status' => 'draft']);
        $this->invoice('INV-CANCELLED', '2026-10-01', ['status' => 'cancelled']);
        $other = Company::factory()->create(['is_active' => true]);
        $this->invoice('INV-OTHER', '2026-08-01', ['company_id' => $other->id, 'customer_id' => Customer::factory()->create(['company_id' => $other->id])->id]);
    }

    /** @param array<string, mixed> $query */
    private function listed(array $query = []): array
    {
        return $this->actingAs($this->admin)->get(route('admin.billing.receivables', $query))->assertOk()->viewData('page')['props']['invoices']['data'];
    }

    /** @param array<string, mixed> $query @return list<string> */
    private function numbers(array $query = []): array
    {
        return array_column($this->listed($query), 'number');
    }

    public function test_the_open_invoices_come_oldest_due_date_first_and_only_the_companys_own(): void
    {
        $this->seedOpenAndClosed();

        $this->assertSame(['INV-E', 'INV-D', 'INV-C', 'INV-B', 'INV-A'], $this->numbers());
    }

    public function test_a_row_carries_what_a_collector_needs_and_nothing_of_the_customer_beyond_name_and_code(): void
    {
        $this->seedOpenAndClosed();
        $this->sari->forceFill(['phone' => '081234567890', 'email' => 'sari.rahasia@example.test', 'tax_id' => '99.888.777.6-543.210', 'notes' => 'jangan ditagih pagi'])->save();

        $rows = collect($this->listed())->keyBy('number');
        $partial = $rows['INV-D'];

        $this->assertEqualsCanonicalizing(
            ['id', 'number', 'customer', 'status', 'issue_date', 'due_date', 'days_overdue', 'bucket', 'total', 'paid_amount', 'sisa', 'can_remind', 'reminded_today'],
            array_keys($partial),
        );
        $this->assertSame(Invoice::query()->where('number', 'INV-D')->value('public_id'), $partial['id']);
        $this->assertSame(['id' => $this->sari->public_id, 'code' => 'CUST-SARI', 'name' => 'Sari Wulandari'], $partial['customer']);
        $this->assertSame(['partial', '2026-09-01', 50, 'b31_60', '250000.00', '100000.00', '150000.00'], [$partial['status'], $partial['due_date'], $partial['days_overdue'], $partial['bucket'], $partial['total'], $partial['paid_amount'], $partial['sisa']]);
        $this->assertSame([0, 'current'], [$rows['INV-B']['days_overdue'], $rows['INV-B']['bucket']], 'due today is not late yet');
        $this->assertSame([0, 'current'], [$rows['INV-A']['days_overdue'], $rows['INV-A']['bucket']]);
        $this->assertSame([10, 'b1_30'], [$rows['INV-C']['days_overdue'], $rows['INV-C']['bucket']]);
        $this->assertSame('b90_plus', $rows['INV-E']['bucket']);

        $json = (string) json_encode($rows->all());
        foreach (['081234567890', 'sari.rahasia@example.test', '99.888.777.6-543.210', 'jangan ditagih pagi'] as $private) {
            $this->assertStringNotContainsString($private, $json, $private);
        }
    }

    /** @return iterable<string, array{0: int, 1: string}> days past due, the bucket the invoice lands in */
    public static function boundaries(): iterable
    {
        yield 'due in 3 days' => [-3, 'current'];
        yield 'due today' => [0, 'current'];
        yield 'yesterday' => [1, 'b1_30'];
        yield 'thirty days' => [30, 'b1_30'];
        yield 'thirty-one days' => [31, 'b31_60'];
        yield 'sixty days' => [60, 'b31_60'];
        yield 'sixty-one days' => [61, 'b61_90'];
        yield 'ninety days' => [90, 'b61_90'];
        yield 'ninety-one days' => [91, 'b90_plus'];
    }

    #[DataProvider('boundaries')]
    public function test_a_bucket_names_the_same_invoices_as_the_customer_view_does(int $daysPast, string $bucket): void
    {
        $this->invoice('INV-EDGE', now()->subDays($daysPast)->toDateString());
        $this->invoice('INV-FAR', now()->addDays(40)->toDateString());

        $row = collect($this->listed())->firstWhere('number', 'INV-EDGE');
        $customerRow = BillingService::receivables()[0];

        $this->assertSame($bucket, $row['bucket']);
        $this->assertContains('INV-EDGE', $this->numbers(['bucket' => $bucket]), 'the filter keeps it');
        foreach (array_diff(ReceivableInvoicesQuery::BUCKETS, [$bucket]) as $other) {
            $this->assertNotContains('INV-EDGE', $this->numbers(['bucket' => $other]), "not in {$other}");
        }
        // The customer view counts the same invoice in the same column.
        $this->assertGreaterThanOrEqual(100000, (float) $customerRow[$bucket], 'the customer view has the money in that bucket');
    }

    public function test_search_finds_an_invoice_by_its_number_the_customers_name_or_code(): void
    {
        $this->seedOpenAndClosed();

        $this->assertSame(['INV-C'], $this->numbers(['search' => 'INV-C']));
        $this->assertSame(['INV-E', 'INV-C', 'INV-A'], $this->numbers(['search' => 'budi']));
        $this->assertSame(['INV-D', 'INV-B'], $this->numbers(['search' => 'CUST-SARI']));
        $this->assertSame([], $this->numbers(['search' => 'tidak ada']));
        $this->assertSame([], $this->numbers(['search' => '%']), 'a typed percent is text, not a wildcard');
    }

    public function test_search_reads_the_name_the_invoice_was_issued_to(): void
    {
        $this->invoice('INV-OLD', '2026-10-01', ['customer_name_snapshot' => 'PT Nama Lama']);
        $this->budi->update(['name' => 'PT Nama Baru']);

        $row = $this->listed(['search' => 'Nama Lama'])[0];

        $this->assertSame('INV-OLD', $row['number']);
        $this->assertSame('PT Nama Lama', $row['customer']['name'], 'named as printed');
    }

    public function test_the_due_range_keeps_both_ends(): void
    {
        $this->seedOpenAndClosed();

        $this->assertSame(['INV-C', 'INV-B'], $this->numbers(['due_from' => '2026-10-11', 'due_to' => '2026-10-21']));
        $this->assertSame(['INV-B', 'INV-A'], $this->numbers(['due_from' => '2026-10-21']));
        $this->assertSame(['INV-E', 'INV-D'], $this->numbers(['due_to' => '2026-09-01']));
        $this->assertSame(['INV-D'], $this->numbers(['bucket' => 'b31_60', 'due_to' => '2026-09-30']));
    }

    public function test_a_filter_that_is_not_a_filter_is_refused(): void
    {
        $this->actingAs($this->admin)
            ->getJson(route('admin.billing.receivables', ['bucket' => 'x', 'due_from' => '21/10/2026', 'due_to' => '2026-01-01', 'search' => str_repeat('a', 101)]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['bucket' => 'Kelompok umur tidak dikenal.', 'due_from' => 'Format jatuh tempo awal harus tahun-bulan-tanggal (YYYY-MM-DD).', 'search']);

        $this->getJson(route('admin.billing.receivables', ['due_from' => '2026-10-21', 'due_to' => '2026-10-01']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['due_to' => 'Jatuh tempo akhir tidak boleh sebelum jatuh tempo awal.']);
    }

    public function test_the_list_pages_in_25s_by_a_page_name_of_its_own(): void
    {
        foreach (range(1, 30) as $number) {
            $this->invoice(sprintf('INV-%02d', $number), now()->addDays($number)->toDateString());
        }

        $page = $this->actingAs($this->admin)->get(route('admin.billing.receivables', ['invoices_page' => 2, 'page' => 1]))->assertOk()->viewData('page')['props'];

        $this->assertCount(5, $page['invoices']['data']);
        $this->assertSame(['current_page' => 2, 'last_page' => 2, 'per_page' => 25, 'total' => 30], array_intersect_key($page['invoices'], array_flip(['current_page', 'last_page', 'per_page', 'total'])));
        $this->assertSame(1, $page['rows']['meta']['current_page'], 'the customer list keeps its own page');
        $this->assertSame('INV-26', $page['invoices']['data'][0]['number']);
    }

    public function test_the_page_still_carries_the_customer_list_and_what_the_buttons_need(): void
    {
        $this->seedOpenAndClosed();

        $this->actingAs($this->admin)
            ->get(route('admin.billing.receivables'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Billing/Receivables')
                ->has('rows.data', 2)
                ->where('totals.invoice_count', 5)
                ->where('invoiceFilters', ['search' => null, 'bucket' => null, 'due_from' => null, 'due_to' => null])
                ->where('can.remind', true)
                ->where('can.export', true)
                ->has('invoices.data', 5));

        $this->get(route('admin.billing.receivables', ['bucket' => 'b1_30', 'search' => ' budi ']))
            ->assertInertia(fn (Assert $page) => $page->where('invoiceFilters', ['search' => 'budi', 'bucket' => 'b1_30', 'due_from' => null, 'due_to' => null]));
    }

    public function test_a_viewer_sees_the_list_without_the_buttons(): void
    {
        $this->seedOpenAndClosed();
        $viewer = User::factory()->create(['company_id' => $this->admin->company_id, 'email_verified_at' => now(), 'is_active' => true]);
        $this->grantPermissions($viewer, ['app.web', 'billing.view']);

        $this->actingAs($viewer)
            ->get(route('admin.billing.receivables'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('can.remind', false)->where('can.export', false)->has('invoices.data', 5));
    }

    public function test_a_reload_of_one_list_does_not_build_the_other(): void
    {
        $this->seedOpenAndClosed();
        $invoices = $this->actingAs($this->admin)->withHeaders($this->partialReload('Admin/Billing/Receivables', 'invoices,invoiceFilters'))->get(route('admin.billing.receivables'))->assertOk()->json('props');
        $customers = $this->withHeaders($this->partialReload('Admin/Billing/Receivables', 'rows,totals,filters'))->get(route('admin.billing.receivables'))->assertOk()->json('props');

        $this->assertArrayHasKey('invoices', $invoices);
        $this->assertArrayNotHasKey('rows', $invoices);
        $this->assertArrayNotHasKey('totals', $invoices);
        $this->assertArrayHasKey('rows', $customers);
        $this->assertArrayNotHasKey('invoices', $customers);
    }

    public function test_the_list_costs_the_same_queries_for_five_rows_and_for_twenty_five(): void
    {
        $headers = $this->partialReload('Admin/Billing/Receivables', 'invoices');
        $this->actingAs($this->admin);
        $count = function () use ($headers): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->withHeaders($headers)->get(route('admin.billing.receivables'))->assertOk();

            return count(DB::getQueryLog());
        };

        foreach (range(1, 5) as $number) {
            $this->invoice(sprintf('INV-S%02d', $number), now()->addDays($number)->toDateString(), ['customer_id' => Customer::factory()->create(['company_id' => $this->admin->company_id])->id]);
        }
        $count(); // warm the permission cache and the company lookups
        $few = $count();

        foreach (range(6, 25) as $number) {
            $this->invoice(sprintf('INV-S%02d', $number), now()->addDays($number)->toDateString(), ['customer_id' => Customer::factory()->create(['company_id' => $this->admin->company_id])->id]);
        }

        $this->assertSame($few, $count(), 'one query per page, none per row');
    }

    public function test_a_row_says_when_the_invoice_was_reminded_today_and_a_deleted_customer_has_nobody_to_remind(): void
    {
        $reminded = $this->invoice('INV-REM', '2026-10-15');
        $this->invoice('INV-FREE', '2026-10-16');
        Cache::put(BillingService::reminderKey($this->admin->company_id, $reminded->id, self::TODAY), true, now()->endOfDay());
        $this->invoice('INV-GONE', '2026-10-17', ['customer_id' => $this->sari->id, 'customer_name_snapshot' => 'Sari Wulandari']);
        $this->sari->delete();

        $rows = collect($this->listed())->keyBy('number');

        $this->assertTrue($rows['INV-REM']['reminded_today']);
        $this->assertFalse($rows['INV-FREE']['reminded_today']);
        $this->assertFalse($rows['INV-GONE']['can_remind']);
        $this->assertTrue($rows['INV-FREE']['can_remind']);
        $this->assertSame('Sari Wulandari', $rows['INV-GONE']['customer']['name'], 'a deleted customer keeps its row');
    }

    public function test_the_export_is_a_csv_of_the_open_invoices_with_the_words_of_the_screen(): void
    {
        $this->seedOpenAndClosed();

        $response = $this->actingAs($this->admin)->get(route('admin.billing.receivables.export', ['format' => 'csv']))->assertOk();
        $lines = array_map(fn (string $line): array => str_getcsv($line, escape: ''), explode("\n", trim(ltrim($response->streamedContent(), "\xEF\xBB\xBF"))));

        $this->assertStringStartsWith('text/csv', (string) $response->headers->get('Content-Type'));
        $this->assertSame('0', $response->headers->get('X-Export-Truncated'));
        $this->assertSame(['Nomor tagihan', 'Pelanggan', 'Kode pelanggan', 'Tanggal terbit', 'Jatuh tempo', 'Lewat jatuh tempo (hari)', 'Umur', 'Total', 'Terbayar', 'Sisa', 'Status'], $lines[0]);
        $this->assertSame(['INV-E', 'INV-D', 'INV-C', 'INV-B', 'INV-A'], array_column(array_slice($lines, 1), 0));
        $this->assertSame(['INV-D', 'Sari Wulandari', 'CUST-SARI', '2026-06-01', '2026-09-01', '50', '31-60 hari', '250000.00', '100000.00', '150000.00', 'Lewat jatuh tempo'], $lines[2]);
        $this->assertSame('Belum jatuh tempo', $lines[5][6]);
        $this->assertSame('Belum dibayar', $lines[5][10]);
    }

    public function test_the_export_follows_the_filters_of_the_list_and_is_audited_without_the_search_text(): void
    {
        $this->seedOpenAndClosed();

        $rows = array_slice(array_map(fn (string $line): array => str_getcsv($line, escape: ''), explode("\n", trim(ltrim($this->actingAs($this->admin)->get(route('admin.billing.receivables.export', ['bucket' => 'b1_30', 'search' => 'budi']))->streamedContent(), "\xEF\xBB\xBF")))), 1);

        $this->assertSame(['INV-C'], array_column($rows, 0));

        $audit = Activity::query()->where('log_name', 'export')->where('description', 'export.csv')->latest('id')->firstOrFail();
        $this->assertSame('admin.billing.receivables.export', $audit->properties['report']);
        $this->assertSame(1, $audit->properties['rows']);
        $this->assertStringNotContainsString('budi', (string) json_encode($audit->properties));
    }

    public function test_the_export_renders_a_pdf(): void
    {
        $this->seedOpenAndClosed();

        $pdf = $this->actingAs($this->admin)->get(route('admin.billing.receivables.export', ['format' => 'pdf', 'bucket' => 'b31_60', 'due_to' => '2026-10-21']))->assertOk();

        $this->assertSame('application/pdf', $pdf->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $pdf->streamedContent());
    }

    public function test_the_export_needs_the_export_permission_and_a_sound_filter(): void
    {
        $this->seedOpenAndClosed();
        $viewer = User::factory()->create(['company_id' => $this->admin->company_id, 'email_verified_at' => now(), 'is_active' => true]);
        $this->grantPermissions($viewer, ['app.web', 'billing.view']);
        $nobody = User::factory()->create(['company_id' => $this->admin->company_id, 'email_verified_at' => now(), 'is_active' => true]);
        $this->grantPermissions($nobody, ['app.web']);

        $this->actingAs($viewer)->get(route('admin.billing.receivables.export'))->assertForbidden();
        $this->actingAs($nobody)->get(route('admin.billing.receivables.export'))->assertForbidden();
        $this->actingAs($this->admin)->getJson(route('admin.billing.receivables.export', ['bucket' => 'x']))->assertUnprocessable();
    }

    public function test_an_invoice_due_within_days_filter_on_the_invoice_list_counts_from_the_companys_today(): void
    {
        $this->seedOpenAndClosed();
        $this->invoice('INV-IN3', now()->addDays(3)->toDateString());
        $this->invoice('INV-IN4', now()->addDays(4)->toDateString(), ['status' => 'partial', 'paid_amount' => 1000]);
        $this->invoice('INV-IN2PAID', now()->addDays(2)->toDateString(), ['status' => 'paid', 'paid_amount' => 100000]);

        $numbers = fn (array $query): array => collect($this->actingAs($this->admin)->get(route('admin.invoices.index', $query + ['per_page' => 50]))->assertOk()->viewData('page')['props']['invoices']['data'])->pluck('number')->sort()->values()->all();

        $this->assertSame(['INV-B', 'INV-IN3'], $numbers(['due_within' => 3]), 'today through three days ahead, open invoices only');
        $this->assertSame(['INV-B', 'INV-IN3', 'INV-IN4'], $numbers(['due_within' => 4]));
        $this->assertSame(['INV-B'], $numbers(['due_within' => 1]));
        $this->assertCount(11, $numbers(['due_within' => 0]), 'a value that is no window is ignored');
        $this->assertCount(11, $numbers(['due_within' => 'abc']));

        $this->get(route('admin.invoices.index', ['due_within' => 3]))
            ->assertInertia(fn (Assert $page) => $page->where('filters.due_within', 3));
        $this->get(route('admin.invoices.index', ['due_within' => 9999]))
            ->assertInertia(fn (Assert $page) => $page->where('filters.due_within', null));
    }
}
