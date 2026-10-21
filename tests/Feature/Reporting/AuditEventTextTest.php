<?php

namespace Tests\Feature\Reporting;

use App\Models\Core\Location;
use App\Models\User;
use App\Services\Core\AuditService;
use App\Support\AuditEventText;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\Models\Category;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\Unit;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;
use Tests\Traits\CreatesReportingUsers;
use Tests\Traits\ParsesCsvDownloads;

/**
 * The one Indonesian sentence of an audit row (F1-D03, F4-D14, F9-D08, F9-U03): the same on the audit page, in its
 * search and export and on the dashboard. Every event the code writes has a sentence; a pair nobody worded still
 * reads as itself, never as a bare code.
 */
class AuditEventTextTest extends TestCase
{
    use CreatesReportingUsers;
    use ParsesCsvDownloads;
    use RefreshDatabase;

    private User $admin;

    private int $companyId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        app()['cache']->forget('spatie.permission.cache');

        $this->admin = $this->userWithRole('admin');
        $this->companyId = (int) $this->admin->company_id;
        $this->actingAs($this->admin);
    }

    /** @return array<string, array{0: string, 1: string, 2: array<string, mixed>, 3: string}> */
    public static function sentences(): array
    {
        return [
            // The events of the flow-audit lane (design §3.3).
            'ticket reopened by staff' => ['ticket', 'reopened', ['by' => 'staff', 'reason' => 'Masih mati'], 'Tiket dibuka kembali oleh staf'],
            'ticket reopened by the customer' => ['ticket', 'reopened', ['by' => 'customer'], 'Tiket dibuka kembali oleh pelanggan'],
            'ticket reopened, nothing kept' => ['ticket', 'reopened', [], 'Tiket dibuka kembali'],
            'customer rated and closed' => ['ticket', 'customer_confirmed', ['rating' => 4, 'closed' => true], 'Pelanggan memberi nilai 4 dari 5 dan tiket ditutup'],
            'customer rated only' => ['ticket', 'customer_confirmed', ['rating' => 5, 'closed' => false], 'Pelanggan memberi nilai 5 dari 5'],
            'customer confirmed' => ['ticket', 'customer_confirmed', ['closed' => true], 'Pelanggan mengonfirmasi dan tiket ditutup'],
            'midtrans callback' => ['payment_attempt', 'callback_received', ['transaction_status' => 'settlement', 'status_code' => '200'], 'Midtrans mengirim kabar pembayaran: lunas'],
            'midtrans callback, unknown status' => ['payment_attempt', 'callback_received', ['transaction_status' => 'on_hold'], 'Midtrans mengirim kabar pembayaran: on hold'],
            'midtrans callback, nothing kept' => ['payment_attempt', 'callback_received', [], 'Midtrans mengirim kabar pembayaran'],
            'cancel requested' => ['payment_attempt', 'cancel_requested', ['order_id' => 'INV-1'], 'Pembatalan ke Midtrans diminta'],
            'reminder sent' => ['invoice', 'reminder_sent', ['channels' => ['mail', 'whatsapp']], 'Pengingat tagihan dikirim lewat email, WhatsApp'],
            'reminder sent, nothing kept' => ['invoice', 'reminder_sent', [], 'Pengingat tagihan dikirim'],
            'draft updated' => ['invoice', 'draft_updated', ['changed' => ['due_date']], 'Draf tagihan diubah'],
            'stock received from a supplier' => ['inventory', 'stock_received', ['supplier_name' => 'PT Kabel Jaya'], 'Stok diterima dari PT Kabel Jaya'],
            'stock received' => ['inventory', 'stock_received', ['quantity' => 5], 'Stok diterima'],
            'nas sync requested' => ['nas_device', 'sync_requested', ['asset_code' => 'NAS-1'], 'Sinkronisasi router ke RADIUS diminta'],
            'document uploaded' => ['customer', 'document_uploaded', ['type' => 'KTP'], 'Dokumen pelanggan diunggah'],
            'document deleted' => ['customer', 'document_deleted', ['type' => 'KTP'], 'Dokumen pelanggan dihapus'],
            'subsidiary requested' => ['platform', 'tenant.subsidiary_requested', ['name' => 'Cabang Utara'], 'Anak perusahaan Cabang Utara diminta'],
            'subsidiary requested, nothing kept' => ['platform', 'tenant.subsidiary_requested', [], 'Anak perusahaan diminta'],
            'subsidiary request created' => ['platform', 'tenant.subsidiary_request_closed', ['name' => 'Cabang Utara', 'outcome' => 'created'], 'Permintaan anak perusahaan Cabang Utara dibuat'],
            'subsidiary request rejected' => ['platform', 'tenant.subsidiary_request_closed', ['outcome' => 'rejected', 'reason' => 'Kode bentrok'], 'Permintaan anak perusahaan ditolak'],
            'subsidiary request closed' => ['platform', 'tenant.subsidiary_request_closed', [], 'Permintaan anak perusahaan ditutup'],

            // The roles of a company (tenant-roles).
            'role created' => ['security', 'role.created', ['name' => 'Kasir'], 'Peran Kasir dibuat'],
            'role updated' => ['security', 'role.updated', ['name' => 'Kasir'], 'Peran Kasir diubah'],
            'role deleted' => ['security', 'role.deleted', ['name' => 'Kasir'], 'Peran Kasir dihapus'],
            'role created, nothing kept' => ['security', 'role.created', [], 'Peran dibuat'],
            'roles attached' => ['security', 'role.attached', ['user_id' => 3, 'roles' => ['Kasir', 'Teknisi']], 'Peran Kasir, Teknisi ditambahkan ke pengguna'],
            'roles detached' => ['security', 'role.detached', ['user_id' => 3, 'roles' => ['Kasir']], 'Peran Kasir dicabut dari pengguna'],
            'role attached, nothing kept' => ['security', 'role.attached', [], 'Peran ditambahkan ke pengguna'],
            'role detached, nothing kept' => ['security', 'role.detached', [], 'Peran dicabut dari pengguna'],

            // The rows the audit page showed raw before (flow audit F1-D03, F4-D14, F9-D08).
            'stock issued' => ['inventory', 'stock_issued', [], 'Stok dikeluarkan'],
            'adjustment requested' => ['inventory', 'stock_adjustment_requested', [], 'Penyesuaian stok diajukan'],
            'request cancelled' => ['inventory_request', 'cancelled', [], 'Permintaan barang dibatalkan'],
            'request created and submitted' => ['inventory_request', 'created_and_submitted', [], 'Permintaan barang dibuat dan diajukan'],
            'return received' => ['material_return_case', 'received_accepted', [], 'Pengembalian material diterima gudang'],
            'work order rejected' => ['work_order', 'rejected', [], 'SPK dikembalikan untuk diperbaiki'],
            'work order item recorded' => ['work_order', 'item_recorded', [], 'Material SPK dicatat'],
            'work order evidence' => ['work_order', 'evidence_uploaded', [], 'Bukti SPK diunggah'],
            'membership granted' => ['company', 'membership_granted', [], 'Pengguna diberi akses ke perusahaan'],
            'company created' => ['company', 'company_created', [], 'Perusahaan dibuat'],
            'admin role assigned' => ['auth', 'admin_role_assigned', [], 'Admin diberi peran'],
            'api token issued' => ['security', 'api.token_issued', [], 'Token aplikasi seluler dibuat'],
            'model event' => ['service_subscription', 'updated', [], 'Langganan diubah'],
            'lifecycle event' => ['product', 'deactivated', [], 'Barang dinonaktifkan'],
            'a support row without its subject' => ['support', 'user.two_factor_reset', [], 'Akses dukungan oleh tim Inbils'],
        ];
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    #[DataProvider('sentences')]
    public function test_describe_words_each_event(string $log, string $description, array $properties, string $expected): void
    {
        $this->assertSame($expected, AuditEventText::describe($log, $description, $properties));
    }

    public function test_an_unknown_pair_reads_as_itself_with_spaces_for_underscores(): void
    {
        $this->assertSame('billing: invoice generated', AuditEventText::describe('billing', 'invoice_generated'));
        $this->assertSame('inventory request: some new thing', AuditEventText::describe('inventory_request', 'some_new_thing'));
        $this->assertSame('aktivitas: something', AuditEventText::describe(null, 'something'));
        $this->assertSame('ticket: constructor', AuditEventText::describe('ticket', 'constructor'), 'a code that is also a member name of an object is no word of the dictionary');
    }

    public function test_a_property_is_never_a_way_to_inject_text_or_a_long_detail(): void
    {
        $name = str_repeat('x', 200);
        $text = AuditEventText::describe('security', 'role.created', ['name' => $name]);

        $this->assertSame('Peran '.str_repeat('x', 80).' dibuat', $text);
        $this->assertSame('Peran dibuat', AuditEventText::describe('security', 'role.created', ['name' => ['nested']]));
        $this->assertSame('Peran dibuat', AuditEventText::describe('security', 'role.created', ['name' => '   ']));
    }

    /**
     * Every action the code writes has a sentence of its own: the pairs are read from the source of the writers, so
     * an event added later without a sentence fails here instead of showing as a code.
     */
    public function test_every_event_the_code_writes_has_a_sentence(): void
    {
        $written = $this->writtenPairs();
        $this->assertGreaterThan(150, count($written), 'the source scan found the writers');

        $raw = [];

        foreach ($written as [$log, $description]) {
            if ($this->isFallback($log, $description)) {
                $raw[] = $log.'.'.$description;
            }
        }

        $this->assertSame([], $raw, 'events with no sentence');
    }

    /** @return list<array{0: string, 1: string}> */
    private function writtenPairs(): array
    {
        $pairs = [];
        $sources = Finder::create()->files()->name('*.php')->in([app_path(), ...glob(base_path('Modules/*/app')) ?: []]);

        foreach ($sources as $file) {
            $file = $file->getPathname();
            $source = (string) file_get_contents($file);

            // A code built by concatenation ('export.'.$format) is listed with its values below.
            if (preg_match_all("/AuditService::log\(\s*'([a-z_]+)',\s*'([A-Za-z_.]+)'(?!\s*\.)/", $source, $m, PREG_SET_ORDER)) {
                foreach ($m as $hit) {
                    $pairs[] = [$hit[1], $hit[2]];
                }
            }

            // "$cond ? 'a' : 'b'" in the action position.
            if (preg_match_all("/AuditService::log\(\s*'([a-z_]+)',\s*[^,']+\?\s*'([A-Za-z_.]+)'\s*:\s*'([A-Za-z_.]+)'/", $source, $m, PREG_SET_ORDER)) {
                foreach ($m as $hit) {
                    $pairs[] = [$hit[1], $hit[2]];
                    $pairs[] = [$hit[1], $hit[3]];
                }
            }

            if (preg_match_all("/->platform\(\s*'([a-z_.]+)'(?!\s*\.)/", $source, $m)) {
                foreach ($m[1] as $action) {
                    $pairs[] = ['platform', $action];
                }
            }

            if (str_ends_with($file, 'TwoFactorService.php') && preg_match_all("/\\\$this->audit\('([a-z_]+)'/", $source, $m)) {
                foreach ($m[1] as $event) {
                    $pairs[] = ['security', 'two_factor.'.$event];
                    $event === 'admin_reset' || $pairs[] = ['platform', 'two_factor.'.$event];
                }
            }
        }

        // The actions written through a variable: their values, read from where they are chosen.
        foreach (['created', 'refunded', 'settled', 'notified', 'pending', 'failed', 'expired', 'exception', 'cancelled_locally'] as $event) {
            $pairs[] = ['payment_attempt', $event];
        }

        foreach (['export.csv', 'export.pdf'] as $action) {
            $pairs[] = ['export', $action];
        }

        foreach (['activated', 'deactivated', 'restored', 'force_deleted'] as $action) {
            $pairs[] = ['product', $action];
        }

        foreach (['role.created', 'role.updated', 'role.deleted', 'role.attached', 'role.detached'] as $action) {
            $pairs[] = ['security', $action];
            $pairs[] = ['platform', $action];
        }

        foreach (['login.failed', 'lockout'] as $action) {
            $pairs[] = ['security', $action];
            $pairs[] = ['platform', $action];
        }

        foreach (['auth.login', 'auth.logout', 'auth.idle_timeout', 'admin.created'] as $action) {
            $pairs[] = ['platform', $action];
        }

        foreach (['impersonation.started', 'impersonation.mode_changed', 'impersonation.write', 'impersonation.ended', 'user.two_factor_reset', 'user.invitation_resent', 'user.login_unlocked'] as $action) {
            $pairs[] = ['platform', $action];
        }

        foreach (['reminder_sent', 'draft_updated'] as $action) {
            $pairs[] = ['invoice', $action];
        }

        foreach (['callback_received', 'cancel_requested'] as $action) {
            $pairs[] = ['payment_attempt', $action];
        }

        foreach (['sync_requested'] as $action) {
            $pairs[] = ['nas_device', $action];
        }

        foreach (['document_uploaded', 'document_deleted'] as $action) {
            $pairs[] = ['customer', $action];
        }

        foreach (['reopened', 'customer_confirmed'] as $action) {
            $pairs[] = ['ticket', $action];
        }

        foreach (['tenant.subsidiary_requested', 'tenant.subsidiary_request_closed'] as $action) {
            $pairs[] = ['platform', $action];
        }

        return array_values(array_unique($pairs, SORT_REGULAR));
    }

    private function isFallback(string $log, string $description): bool
    {
        return AuditEventText::describe($log, $description) === str_replace('_', ' ', $log).': '.str_replace('_', ' ', $description);
    }

    private function product(): Product
    {
        $unit = Unit::factory()->create(['company_id' => $this->companyId]);
        $category = Category::factory()->create(['company_id' => $this->companyId, 'unit_id' => $unit->id]);

        return Product::factory()->create(['company_id' => $this->companyId, 'category_id' => $category->id, 'unit_id' => $unit->id, 'name' => 'Kabel UTP Cat6']);
    }

    public function test_subjects_are_named_in_one_query_per_type(): void
    {
        $locations = Location::factory()->count(3)->create(['company_id' => $this->companyId]);
        $product = $this->product();

        $rows = $locations->map(fn (Location $location): Activity => AuditService::log('location', 'created', [], $location))->all();
        $stock = [
            AuditService::log('inventory', 'stock_received', ['product_id' => $product->id, 'location_id' => $locations[0]->id, 'quantity' => 5]),
            AuditService::log('inventory', 'stock_adjustment', ['product_id' => $product->id, 'location_id' => $locations[0]->id]),
        ];
        $gone = AuditService::log('inventory_request', 'cancelled', ['code' => 'REQ-2026-00001']);
        $nothing = AuditService::log('billing', 'invoice_generated', ['amount' => 1]);

        $loaded = Activity::query()->whereKey(collect([...$rows, ...$stock, $gone, $nothing])->map->getKey())->get();
        $this->assertCount(7, $loaded);

        DB::enableQueryLog();
        $labels = AuditEventText::subjects($loaded);
        $queries = collect(DB::getQueryLog())->pluck('query');
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(2, $queries->count(), $queries->implode("\n"));

        foreach ($rows as $i => $row) {
            $this->assertSame($locations[$i]->code, $labels[$row->getKey()], 'the code of the record, never its type or id');
        }

        $this->assertSame('Kabel UTP Cat6', $labels[$stock[0]->getKey()], 'a stock row names its product');
        $this->assertSame('Kabel UTP Cat6', $labels[$stock[1]->getKey()]);
        $this->assertSame('REQ-2026-00001', $labels[$gone->getKey()], 'a subject deleted since keeps the code the row kept');
        $this->assertArrayNotHasKey($nothing->getKey(), $labels, 'a row with nothing to name has no label');
    }

    public function test_a_soft_deleted_subject_is_still_named(): void
    {
        $location = Location::factory()->create(['company_id' => $this->companyId]);
        $row = AuditService::log('location', 'deleted', [], $location);
        $location->delete();

        $labels = AuditEventText::subjects(Activity::query()->whereKey($row->getKey())->get());

        $this->assertSame($location->code, $labels[$row->getKey()]);
    }

    public function test_the_page_carries_the_sentence_and_the_subject_of_every_row(): void
    {
        AuditService::log('inventory_request', 'cancelled', ['code' => 'REQ-2026-00007']);
        AuditService::log('inventory', 'stock_received', ['product_id' => $this->product()->id]);

        $rows = collect($this->get(route('admin.reports.audit-log'))->assertOk()->inertiaProps('data.data'));

        $cancelled = $rows->firstWhere('description', 'cancelled');
        $this->assertSame('Permintaan barang dibatalkan', $cancelled['text']);
        $this->assertSame('REQ-2026-00007', $cancelled['subject_label']);

        $received = $rows->firstWhere('description', 'stock_received');
        $this->assertSame('Stok diterima', $received['text']);
        $this->assertSame('Kabel UTP Cat6', $received['subject_label'], 'the stock row that showed "-" before names its product');
    }

    public function test_search_finds_a_row_by_the_words_it_shows(): void
    {
        AuditService::log('inventory_request', 'cancelled', []);
        AuditService::log('ticket', 'assigned', []);
        AuditService::log('billing', 'unrelated_thing', []);

        $found = fn (string $term): array => collect($this->get(route('admin.reports.audit-log', ['search' => $term]))->assertOk()->inertiaProps('data.data'))
            ->pluck('description')
            ->all();

        $this->assertSame(['cancelled'], $found('dibatalkan'), 'the sentence, not the code');
        $this->assertSame(['cancelled'], $found('Permintaan barang'), 'the case does not matter');
        $this->assertContains('assigned', $found('ditugaskan'));
        $this->assertNotContains('cancelled', $found('ditugaskan'));
        $this->assertSame([], $found('kalimat yang tidak ada'));
        $this->assertContains('unrelated_thing', $found('unrelated'), 'the stored code still matches');
    }

    public function test_search_never_reaches_another_company(): void
    {
        AuditService::log('inventory_request', 'cancelled', []);
        $other = $this->userWithRole('admin');
        $this->actingAs($other);
        AuditService::log('inventory_request', 'cancelled', []);
        $this->actingAs($this->admin);

        $rows = $this->get(route('admin.reports.audit-log', ['search' => 'dibatalkan']))->assertOk()->inertiaProps('data.data');

        $this->assertCount(1, $rows);
    }

    public function test_the_export_writes_the_sentence_and_the_subject(): void
    {
        AuditService::log('inventory_request', 'cancelled', ['code' => 'REQ-2026-00007']);
        AuditService::log('billing', 'invoice_generated', []);

        $records = collect($this->csvRecords($this->get(route('admin.reports.audit-log.export', ['format' => 'csv']))));

        $row = $records->firstWhere('Aksi', 'cancelled');
        $this->assertSame('Permintaan barang dibatalkan', $row['Deskripsi']);
        $this->assertSame('REQ-2026-00007', $row['Subjek']);
        $this->assertSame('billing: invoice generated', $records->firstWhere('Aksi', 'invoice_generated')['Deskripsi']);
        $this->assertSame('', $records->firstWhere('Aksi', 'invoice_generated')['Subjek']);

        $searched = $this->csvRecords($this->get(route('admin.reports.audit-log.export', ['format' => 'csv', 'search' => 'dibatalkan'])));
        $this->assertSame(['Permintaan barang dibatalkan'], array_column($searched, 'Deskripsi'), 'the export filters on the same words');
    }

    public function test_the_export_time_is_in_the_company_zone(): void
    {
        $this->admin->company->forceFill(['timezone' => 'Asia/Jakarta'])->save();
        $row = AuditService::log('billing', 'invoice_generated', []);
        $row->forceFill(['created_at' => '2026-10-20 17:30:00'])->save();

        $record = collect($this->csvRecords($this->get(route('admin.reports.audit-log.export', ['format' => 'csv', 'date_from' => '2026-10-20', 'date_to' => '2026-10-21']))))
            ->firstWhere('ID', $row->public_id);

        $this->assertSame('2026-10-21 00:30:00', $record['Waktu'], 'UTC 17:30 is 00:30 the next day in Jakarta');
    }

    public function test_the_dictionary_never_reads_a_property_the_page_would_hide(): void
    {
        $row = AuditService::log('security', 'role.created', ['name' => 'Rahasia']);

        $this->assertSame('Peran Rahasia dibuat', AuditEventText::text($row));
        $this->assertSame('Peran dibuat', AuditEventText::text($row, withProperties: false));
    }

    public function test_the_collection_form_of_subjects_accepts_any_collection(): void
    {
        $location = Location::factory()->create(['company_id' => $this->companyId]);
        $row = AuditService::log('location', 'created', [], $location);

        $labels = AuditEventText::subjects(collect([Activity::query()->findOrFail($row->getKey())]));

        $this->assertSame($location->code, $labels[$row->getKey()]);
        $this->assertSame([], AuditEventText::subjects(new EloquentCollection()));
        $this->assertSame('Tiket ditugaskan', AuditEventText::describe('ticket', 'assigned'));
    }
}
