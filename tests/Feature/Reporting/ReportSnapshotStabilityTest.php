<?php

namespace Tests\Feature\Reporting;

use App\Models\Core\Company;
use App\Models\Core\Customer;
use App\Models\Core\CustomerAddress;
use App\Models\Core\EmployeeProfile;
use App\Models\Core\OrganizationUnit;
use App\Models\Core\ServiceSubscription;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Testing\TestResponse;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\Payment;
use Modules\Billing\Support\InvoiceParty;
use Modules\Reporting\Queries\BusinessMetricsQuery;
use Modules\Reporting\Queries\SalesKpiQuery;
use Modules\Reporting\Queries\SlaComplianceQuery;
use Modules\Reporting\Queries\TechnicianPerformanceQuery;
use Modules\Service\Models\ServicePackage;
use Modules\Service\Models\ServicePackageCategory;
use Modules\SPK\Database\Factories\WorkOrderFactory;
use Modules\SPK\Models\WorkOrder;
use Modules\Ticketing\Models\Ticket;
use Modules\Ticketing\Models\TicketCategory;
use Modules\Ticketing\Services\TicketService;
use Tests\TestCase;
use Tests\Traits\BuildsReportingWorld;
use Tests\Traits\CreatesReportingUsers;

/**
 * Reports group history by what a record said when it happened, not by what its parents say today (spec
 * .scratch/master-lifecycle D5, design 4.7): a subscription belongs to the category of the package version it was sold
 * under, a closed ticket to the category it closed under, and a completed SPK or an issued invoice shows the technician
 * or customer it was frozen with. Renaming a category, a technician or a customer, moving a technician to another unit
 * and editing a package (a new version) change nothing about March; what happens afterwards reports under the new values.
 *
 * March is built once in setUp, through the writers' own helpers where they are cheap (TicketService::close,
 * InvoiceParty::freeze) and as SpkService::approve leaves a work order otherwise. Every test then edits one master,
 * compares the whole March book (figures and drill rows) with the one taken before, and adds April activity.
 */
class ReportSnapshotStabilityTest extends TestCase
{
    use BuildsReportingWorld;
    use CreatesReportingUsers;
    use RefreshDatabase;

    private const MARCH = ['date_from' => '2026-03-01', 'date_to' => '2026-03-31'];

    private const APRIL = ['date_from' => '2026-04-01', 'date_to' => '2026-04-30'];

    private const SALES_FIGURES = ['customers_acquired', 'subscriptions_activated', 'active_mrr', 'invoiced', 'collected', 'outstanding'];

    private const BUSINESS_FIGURES = [
        'mrr', 'active_subscriptions', 'new_subscriptions', 'revenue_paid', 'recurring_revenue', 'one_time_revenue',
        'outstanding', 'installation_count',
    ];

    private User $admin;

    private User $manager;

    private User $technician;

    private EmployeeProfile $profile;

    private OrganizationUnit $ops;

    private OrganizationUnit $field;

    private ServicePackageCategory $rumahan;

    private ServicePackageCategory $bisnis;

    private ServicePackage $package;

    private Customer $customer;

    private CustomerAddress $address;

    private TicketCategory $gangguan;

    private ServiceSubscription $subscription;

    private Invoice $paidInvoice;

    private Invoice $openInvoice;

    private WorkOrder $installation;

    private Ticket $onTime;

    private Ticket $late;

    private int $companyId;

    /**
     * March: one Rumahan subscription (100000) with a paid invoice of 100000 and an open one of 100000, its installation
     * completed by "Teknisi Lama" of unit OPS, and two "Gangguan" tickets that technician resolved and a manager closed
     * (one on time, one late).
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->seed(RolePermissionSeeder::class);
        app()['cache']->forget('spatie.permission.cache');

        $this->admin = $this->userWithRole('admin');
        $this->companyId = (int) $this->admin->company_id;
        // The fixtures are pinned to calendar days of the storage timezone (UTC).
        Company::query()->whereKey($this->companyId)->update(['timezone' => 'UTC']);

        $this->ops = OrganizationUnit::factory()->create(['company_id' => $this->companyId, 'path' => 'OPS']);
        $this->field = OrganizationUnit::factory()->create(['company_id' => $this->companyId, 'path' => 'FIELD']);
        $this->technician = $this->member('technician', 'Teknisi Lama');
        $this->profile = EmployeeProfile::factory()->create([
            'company_id' => $this->companyId, 'user_id' => $this->technician->id, 'organization_id' => $this->ops->id, 'status' => 'active',
        ]);
        $this->manager = $this->member('manager', 'Manajer Tiket');

        $this->rumahan = $this->packageCategory('RUMAHAN', 'Rumahan');
        $this->bisnis = $this->packageCategory('BISNIS', 'Bisnis');
        $this->package = ServicePackage::factory()->create([
            'company_id' => $this->companyId, 'service_package_category_id' => $this->rumahan->id, 'price_mrc' => 100000,
        ]);
        $this->customer = Customer::factory()->create([
            'company_id' => $this->companyId, 'name' => 'Budi Pelanggan', 'acquired_by_user_id' => $this->admin->id, 'created_at' => '2026-03-01 08:00:00',
        ]);
        $this->address = CustomerAddress::factory()->create(['customer_id' => $this->customer->id]);

        $this->subscription = $this->sell('2026-03-02', 100000);
        $this->paidInvoice = $this->invoice($this->subscription, 'paid', 100000, 100000, '2026-03-10');
        $this->openInvoice = $this->invoice($this->subscription, 'sent', 100000, 0, '2026-03-20');
        $this->installation = $this->completedSpk('2026-03-20 12:00:00', $this->subscription);

        $this->gangguan = $this->worldCategory($this->companyId, 'Gangguan', 'GGN');
        $this->onTime = $this->resolvedTicket($this->gangguan, '2026-03-10 08:00:00', '2026-03-10 09:00:00', '2026-03-10 10:00:00');
        $this->late = $this->resolvedTicket($this->gangguan, '2026-03-12 08:00:00', '2026-03-12 13:00:00', '2026-03-12 12:00:00');
    }

    // ---------------------------------------------------------------------------------------------
    // Package versions: the category filter follows the version a subscription was sold under
    // ---------------------------------------------------------------------------------------------

    public function test_business_and_sales_category_filter_keep_subscriptions_sold_under_old_category_after_package_moves(): void
    {
        $before = $this->march();

        $this->package->update(['price_mrc' => 120000]);                               // version 2: dearer, still Rumahan
        $this->package->update(['service_package_category_id' => $this->bisnis->id]);  // version 3: moved to Bisnis

        $this->assertSame($before, $this->march(), 'a package edit rewrote March');

        // April: sold under version 3, so it belongs to Bisnis, with its invoice and its installation.
        $sold = $this->sell('2026-04-06', 150000);
        $invoice = $this->invoice($sold, 'sent', 150000, 0, '2026-04-10');
        $installation = $this->completedSpk('2026-04-12 10:00:00', $sold);

        $this->assertSame([1, 3], [$this->subscription->fresh()->packageVersion->version, $sold->fresh()->packageVersion->version]);

        $rumahan = $this->rumahan->id;
        $bisnis = $this->bisnis->id;

        // March stays Rumahan: its subscription, its money, its installation. Bisnis has no March activity at all
        // (its MRR and receivable are today's figures, which hold the April sale).
        $this->assertSame(
            ['customers_acquired' => 1, 'subscriptions_activated' => 1, 'active_mrr' => '100000.00', 'invoiced' => '200000.00', 'collected' => '100000.00', 'outstanding' => '100000.00'],
            $this->salesTotals(self::MARCH, $rumahan),
        );
        $this->assertSame(
            ['customers_acquired' => 1, 'subscriptions_activated' => 0, 'active_mrr' => '150000.00', 'invoiced' => '0.00', 'collected' => '0.00', 'outstanding' => '150000.00'],
            $this->salesTotals(self::MARCH, $bisnis),
        );
        $this->assertSame(
            ['mrr' => 100000.0, 'active_subscriptions' => 1.0, 'new_subscriptions' => 1.0, 'revenue_paid' => 100000.0, 'recurring_revenue' => 100000.0, 'one_time_revenue' => 0.0, 'outstanding' => 100000.0, 'installation_count' => 1.0],
            $this->businessFigures(self::MARCH, $rumahan),
        );
        $this->assertSame(
            ['mrr' => 150000.0, 'active_subscriptions' => 1.0, 'new_subscriptions' => 0.0, 'revenue_paid' => 0.0, 'recurring_revenue' => 0.0, 'one_time_revenue' => 0.0, 'outstanding' => 150000.0, 'installation_count' => 0.0],
            $this->businessFigures(self::MARCH, $bisnis),
        );

        // April is new activity, reported under the new category.
        $this->assertSame(
            ['customers_acquired' => 0, 'subscriptions_activated' => 1, 'active_mrr' => '150000.00', 'invoiced' => '150000.00', 'collected' => '0.00', 'outstanding' => '150000.00'],
            $this->salesTotals(self::APRIL, $bisnis),
        );
        $this->assertSame(
            ['customers_acquired' => 0, 'subscriptions_activated' => 0, 'active_mrr' => '100000.00', 'invoiced' => '0.00', 'collected' => '0.00', 'outstanding' => '100000.00'],
            $this->salesTotals(self::APRIL, $rumahan),
        );
        $this->assertSame(
            ['mrr' => 150000.0, 'active_subscriptions' => 1.0, 'new_subscriptions' => 1.0, 'revenue_paid' => 0.0, 'recurring_revenue' => 0.0, 'one_time_revenue' => 0.0, 'outstanding' => 150000.0, 'installation_count' => 1.0],
            $this->businessFigures(self::APRIL, $bisnis),
        );
        $this->assertSame(
            ['mrr' => 100000.0, 'active_subscriptions' => 1.0, 'new_subscriptions' => 0.0, 'revenue_paid' => 0.0, 'recurring_revenue' => 0.0, 'one_time_revenue' => 0.0, 'outstanding' => 100000.0, 'installation_count' => 0.0],
            $this->businessFigures(self::APRIL, $rumahan),
        );

        // The lists behind the figures follow the same split and tie out to them.
        $this->assertSame([$this->subscription->code], $this->codes('business', 'mrr', self::MARCH, $rumahan));
        $this->assertSame([$sold->code], $this->codes('business', 'mrr', self::MARCH, $bisnis));
        $this->assertSame([$this->openInvoice->number], $this->numbers('business', 'outstanding', self::MARCH, $rumahan));
        $this->assertSame([$invoice->number], $this->numbers('business', 'outstanding', self::APRIL, $bisnis));
        $this->assertSame([$this->installation->code], $this->codes('business', 'installation_count', self::MARCH, $rumahan));
        $this->assertSame([$installation->code], $this->codes('business', 'installation_count', self::APRIL, $bisnis));
        $this->assertSame([], $this->codes('business', 'installation_count', self::MARCH, $bisnis));

        foreach ([['mrr', $rumahan], ['mrr', $bisnis], ['outstanding', $bisnis], ['installation_count', $rumahan]] as [$metric, $category]) {
            $this->assertTrue(
                $this->drill('business', $metric, [...self::MARCH, ...$this->categoryQuery($category)])->inertiaProps('tie_out.matches'),
                "{$metric} ties out to its figure",
            );
        }
    }

    public function test_renaming_a_package_category_and_repricing_a_package_change_no_figure_of_last_month(): void
    {
        $before = $this->march();

        $this->rumahan->update(['name' => 'Residensial']);
        $this->package->update(['price_mrc' => 120000]);                                // version 2, same category

        $this->assertSame($before, $this->march(), 'a rename or a repricing rewrote March');

        // Sold at the new price: both versions answer to the same category, each at what it was sold for.
        $sold = $this->sell('2026-04-06', 120000);

        $this->assertSame([1, 2], [$this->subscription->fresh()->packageVersion->version, $sold->fresh()->packageVersion->version]);
        $this->assertSame('100000.00', $this->subscription->fresh()->mrc_amount, 'the price of an old sale is not the package price');
        $this->assertSame(
            ['customers_acquired' => 0, 'subscriptions_activated' => 1, 'active_mrr' => '220000.00', 'invoiced' => '0.00', 'collected' => '0.00', 'outstanding' => '100000.00'],
            $this->salesTotals(self::APRIL, $this->rumahan->id),
        );
        $this->assertSame(2.0, $this->businessFigures(self::APRIL, $this->rumahan->id)['active_subscriptions']);

        $codes = [$this->subscription->code, $sold->code];
        sort($codes);
        $this->assertSame($codes, $this->codes('business', 'mrr', self::APRIL, $this->rumahan->id));
    }

    // ---------------------------------------------------------------------------------------------
    // Ticket categories: a closed ticket keeps the category it closed under
    // ---------------------------------------------------------------------------------------------

    public function test_sla_by_category_stable_after_category_rename(): void
    {
        $before = $this->march();
        $this->assertSame(['Gangguan' => ['total' => 2, 'compliant' => 1, 'rate' => 50.0]], $this->byCategory($this->sla(self::MARCH)));

        $this->gangguan->update(['name' => 'Gangguan Jaringan']);

        $this->assertSame($before, $this->march(), 'a category rename rewrote March');

        // April: closed after the rename, so it reports under the new name. By category ignores the period (v1).
        $closedAfter = $this->resolvedTicket($this->gangguan, '2026-04-05 08:00:00', '2026-04-05 09:00:00', '2026-04-05 10:00:00');

        $this->assertSame(
            [
                'Gangguan' => ['total' => 2, 'compliant' => 1, 'rate' => 50.0],
                'Gangguan Jaringan' => ['total' => 1, 'compliant' => 1, 'rate' => 100.0],
            ],
            $this->byCategory($this->sla([])),
        );

        $old = $this->drill('sla', 'by_category', ['group' => 'Gangguan']);
        $this->assertEqualsCanonicalizing([$this->onTime->public_id, $this->late->public_id], array_column($old->inertiaProps('rows.data'), 'id'));
        $this->assertSame(['Gangguan', 'Gangguan'], array_column($old->inertiaProps('rows.data'), 'category'));
        $this->assertTrue($old->inertiaProps('tie_out.matches'), 'the old group ties out to its figure');

        $new = $this->drill('sla', 'by_category', ['group' => 'Gangguan Jaringan']);
        $this->assertSame([$closedAfter->public_id], array_column($new->inertiaProps('rows.data'), 'id'));
        $this->assertSame(['Gangguan Jaringan'], array_column($new->inertiaProps('rows.data'), 'category'));
        $this->assertTrue($new->inertiaProps('tie_out.matches'), 'the new group ties out to its figure');
    }

    public function test_technician_tickets_by_category_stable_after_rename(): void
    {
        $before = $this->march();
        $this->assertSame(['Gangguan' => 2], $this->technicianFigures(self::MARCH)['tickets_by_category']);

        $this->gangguan->update(['name' => 'Gangguan Jaringan']);

        $this->assertSame($before, $this->march(), 'a category rename rewrote the technician page of March');

        $closedAfter = $this->resolvedTicket($this->gangguan, '2026-04-05 08:00:00', '2026-04-05 09:00:00', '2026-04-05 10:00:00');

        $this->assertSame(['Gangguan' => 2], $this->technicianFigures(self::MARCH)['tickets_by_category']);
        $this->assertSame(['Gangguan Jaringan' => 1], $this->technicianFigures(self::APRIL)['tickets_by_category']);
        $this->assertEquals(['Gangguan' => 2, 'Gangguan Jaringan' => 1], $this->technicianFigures([])['tickets_by_category']);

        $old = $this->drill('technician', 'tickets_by_category', [...self::MARCH, 'technician_id' => $this->technician->public_id, 'group' => 'Gangguan']);
        $this->assertEqualsCanonicalizing([$this->onTime->public_id, $this->late->public_id], array_column($old->inertiaProps('rows.data'), 'id'));
        $this->assertTrue($old->inertiaProps('tie_out.matches'));

        $new = $this->drill('technician', 'tickets_by_category', [...self::APRIL, 'technician_id' => $this->technician->public_id, 'group' => 'Gangguan Jaringan']);
        $this->assertSame([$closedAfter->public_id], array_column($new->inertiaProps('rows.data'), 'id'));
        $this->assertTrue($new->inertiaProps('tie_out.matches'));
    }

    public function test_a_resolved_ticket_follows_the_live_category_name_until_it_is_closed(): void
    {
        // Design 4.7: the snapshot is written when a ticket closes; until then it can still come back to work.
        $open = $this->resolvedTicket($this->gangguan, '2026-03-15 08:00:00', '2026-03-15 09:00:00', '2026-03-15 10:00:00', close: false);

        $this->gangguan->update(['name' => 'Gangguan Jaringan']);

        $this->assertSame(
            [
                'Gangguan' => ['total' => 2, 'compliant' => 1, 'rate' => 50.0],
                'Gangguan Jaringan' => ['total' => 1, 'compliant' => 1, 'rate' => 100.0],
            ],
            $this->byCategory($this->sla([])),
        );

        $this->actingAs($this->manager);
        TicketService::close($open);
        $this->gangguan->update(['name' => 'Layanan']);

        $this->assertSame(
            [
                'Gangguan' => ['total' => 2, 'compliant' => 1, 'rate' => 50.0],
                'Gangguan Jaringan' => ['total' => 1, 'compliant' => 1, 'rate' => 100.0],
            ],
            $this->byCategory($this->sla([])),
            'closing froze the name the category had then',
        );
    }

    // ---------------------------------------------------------------------------------------------
    // People: a completed SPK and an issued invoice keep who was on them
    // ---------------------------------------------------------------------------------------------

    public function test_renaming_a_technician_or_customer_changes_no_figure_or_row_of_last_month(): void
    {
        $before = $this->march();

        $this->technician->update(['name' => 'Teknisi Baru']);
        $this->customer->update(['name' => 'Budi Baru']);

        $this->assertSame($before, $this->march(), 'a rename rewrote March');
    }

    public function test_moving_a_technician_to_another_unit_changes_no_figure_of_last_month(): void
    {
        $before = $this->march();

        $this->profile->update(['organization_id' => $this->field->id]);

        $this->assertSame($before, $this->march(), 'a move rewrote March');

        // The SPK of April is approved after the move, so it carries the new unit; March keeps the old one.
        $later = $this->completedSpk('2026-04-12 10:00:00');

        $this->assertSame([$this->ops->id, $this->field->id], [$this->installation->fresh()->organization_unit_id, $later->fresh()->organization_unit_id]);
        $this->assertSame(1, $this->technicianFigures(self::MARCH)['spk_completed']);
        $this->assertSame(1, $this->technicianFigures(self::APRIL)['spk_completed']);
        $this->assertSame(2, $this->technicianFigures([])['spk_completed']);
        $this->assertSame([$this->installation->code], $this->codes('technician', 'spk_completed', self::MARCH, technician: true));
        $this->assertSame([$later->code], $this->codes('technician', 'spk_completed', self::APRIL, technician: true));
    }

    public function test_drill_rows_read_snapshots(): void
    {
        // Rows that predate the snapshots (an invoice, an SPK, a ticket nobody froze) still read the live records.
        $legacyInvoice = $this->invoice($this->subscription, 'sent', 50000, 0, '2026-03-25', frozen: false);
        $legacySpk = $this->completedSpk('2026-03-25 12:00:00', frozen: false);
        $notClosed = $this->resolvedTicket($this->gangguan, '2026-03-15 08:00:00', '2026-03-15 09:00:00', '2026-03-15 10:00:00', close: false);

        $this->technician->update(['name' => 'Teknisi Baru']);
        $this->customer->update(['name' => 'Budi Baru']);
        $this->gangguan->update(['name' => 'Gangguan Jaringan']);

        // After the renames: issued, completed and closed with the new names.
        $invoice = $this->invoice($this->subscription, 'sent', 75000, 0, '2026-04-10');
        $spk = $this->completedSpk('2026-04-12 10:00:00');
        $ticket = $this->resolvedTicket($this->gangguan, '2026-04-05 08:00:00', '2026-04-05 09:00:00', '2026-04-05 10:00:00');

        $this->assertEquals(
            [$this->paidInvoice->number => 'Budi Pelanggan'],
            $this->column('business', 'revenue_paid', self::MARCH, 'number', 'customer'),
            'a paid invoice names the customer it was issued to',
        );
        $this->assertEquals(
            [
                $this->openInvoice->number => 'Budi Pelanggan',
                $legacyInvoice->number => 'Budi Baru',
                $invoice->number => 'Budi Baru',
            ],
            $this->column('business', 'outstanding', [], 'number', 'customer'),
            'issued invoices read the snapshot, an unfrozen one the live customer',
        );

        $this->assertEquals(
            [$this->installation->code => 'Teknisi Lama', $legacySpk->code => 'Teknisi Baru', $spk->code => 'Teknisi Baru'],
            $this->column('business', 'installation_count', [], 'code', 'assignee'),
            'completed SPK read the technician they were completed with',
        );
        $this->assertEquals(
            [$this->installation->code => 'Teknisi Lama', $legacySpk->code => 'Teknisi Baru'],
            $this->column('technician', 'spk_completed', self::MARCH, 'code', 'assignee', technician: true),
        );

        $this->assertEquals(
            [
                $this->onTime->code => 'Gangguan', $this->late->code => 'Gangguan',
                $notClosed->code => 'Gangguan Jaringan', $ticket->code => 'Gangguan Jaringan',
            ],
            $this->column('sla', 'total_resolved', [], 'code', 'category'),
            'closed tickets read the category they closed under, an unclosed one the live category',
        );
        $this->assertEquals(
            [$this->onTime->code => 'Gangguan', $this->late->code => 'Gangguan', $notClosed->code => 'Gangguan Jaringan'],
            $this->column('technician', 'tickets_resolved', self::MARCH, 'code', 'category', technician: true),
        );
    }

    // ---------------------------------------------------------------------------------------------
    // Fixtures
    // ---------------------------------------------------------------------------------------------

    private function member(string $role, string $name): User
    {
        $user = User::factory()->create(['company_id' => $this->companyId, 'name' => $name, 'is_active' => true, 'email_verified_at' => now()]);
        $user->assignRole($role);

        return $user;
    }

    private function packageCategory(string $code, string $name): ServicePackageCategory
    {
        return ServicePackageCategory::factory()->create(['company_id' => $this->companyId, 'code' => $code, 'name' => $name]);
    }

    /** A subscription of the package as it is today: it is sold under the latest version. */
    private function sell(string $activatedOn, int $mrc): ServiceSubscription
    {
        return ServiceSubscription::factory()->create([
            'company_id' => $this->companyId, 'customer_id' => $this->customer->id, 'service_package_id' => $this->package->id,
            'installation_address_id' => $this->address->id, 'status' => 'active', 'activation_date' => $activatedOn,
            'created_at' => $activatedOn.' 09:00:00', 'mrc_amount' => $mrc,
        ]);
    }

    /** An invoice as it is once sent: BillingService::send freezes the party with the same helper. */
    private function invoice(ServiceSubscription $subscription, string $status, int $total, int $paid, string $issueDate, bool $frozen = true): Invoice
    {
        $invoice = Invoice::factory()->create([
            'company_id' => $this->companyId, 'customer_id' => $this->customer->id, 'subscription_id' => $subscription->id,
            'created_by' => $this->admin->id, 'type' => 'recurring', 'status' => $status, 'issue_date' => $issueDate,
            'due_date' => Carbon::parse($issueDate)->addDays(14)->toDateString(), 'total' => $total, 'paid_amount' => $paid,
        ]);

        if ($frozen) {
            InvoiceParty::freeze($invoice);
            $invoice->save();
        }

        if ($paid > 0) {
            Payment::query()->create([
                'company_id' => $this->companyId, 'invoice_id' => $invoice->id, 'amount' => $paid, 'method' => 'transfer',
                'paid_at' => $issueDate.' 10:00:00', 'received_by' => $this->admin->id,
            ]);
        }

        return $invoice->fresh();
    }

    /** An installation the technician completed, as SpkService::approve leaves it: technician name and unit frozen. */
    private function completedSpk(string $completedAt, ?ServiceSubscription $subscription = null, bool $frozen = true): WorkOrder
    {
        return WorkOrderFactory::new()->create([
            'company_id' => $this->companyId, 'created_by' => $this->admin->id, 'type' => 'installation', 'status' => 'completed',
            'assigned_to' => $this->technician->id, 'customer_id' => $this->customer->id, 'subscription_id' => $subscription?->id,
            'completed_at' => $completedAt,
            ...($frozen ? [
                'technician_name_snapshot' => $this->technician->name,
                'organization_unit_id' => $this->profile->fresh()->organization_id,
            ] : []),
        ]);
    }

    /** A ticket the technician resolved and, by default, the manager closed (TicketService::close freezes the names). */
    private function resolvedTicket(TicketCategory $category, string $createdAt, string $resolvedAt, string $slaDeadline, bool $close = true): Ticket
    {
        $ticket = $this->worldTicket($this->companyId, $category, $createdAt, null, $resolvedAt, $slaDeadline, 'resolved', $this->technician->id);

        if ($close) {
            $this->actingAs($this->manager);
            TicketService::close($ticket);
        }

        return $ticket->fresh();
    }

    // ---------------------------------------------------------------------------------------------
    // What the reports say
    // ---------------------------------------------------------------------------------------------

    /**
     * Everything the reports say about March, to compare before and after an edit: the SLA and Technician figures,
     * the Sales and Business figures (also per package category) and the rows behind them.
     *
     * @return array<string, mixed>
     */
    private function march(): array
    {
        $technician = ['technician_id' => $this->technician->public_id];
        $book = [
            'sla' => $this->sla(self::MARCH),
            'technician' => $this->technicianFigures(self::MARCH),
            'rows' => [
                'revenue_paid' => $this->rows('business', 'revenue_paid', self::MARCH),
                'outstanding' => $this->rows('business', 'outstanding', self::MARCH),
                'installation_count' => $this->rows('business', 'installation_count', self::MARCH),
                'total_resolved' => $this->rows('sla', 'total_resolved', self::MARCH),
                'by_category' => $this->rows('sla', 'by_category', [...self::MARCH, 'group' => 'Gangguan']),
                'spk_completed' => $this->rows('technician', 'spk_completed', [...self::MARCH, ...$technician]),
                'tickets_resolved' => $this->rows('technician', 'tickets_resolved', [...self::MARCH, ...$technician]),
                'tickets_by_category' => $this->rows('technician', 'tickets_by_category', [...self::MARCH, ...$technician, 'group' => 'Gangguan']),
            ],
        ];

        foreach (['all' => null, 'rumahan' => $this->rumahan->id, 'bisnis' => $this->bisnis->id] as $name => $category) {
            $book['sales'][$name] = $this->salesTotals(self::MARCH, $category);
            $book['business'][$name] = $this->business(self::MARCH, $category);
            // A subscription list shows today's customer and package (design 4.7); which subscriptions are in it is the history.
            $book['rows']["mrr.{$name}"] = $this->codes('business', 'mrr', self::MARCH, $category);
            $book['rows']["revenue_paid.{$name}"] = $this->rows('business', 'revenue_paid', [...self::MARCH, ...$this->categoryQuery($category)]);
        }

        return $book;
    }

    /**
     * @param  array<string, string>  $period
     * @return array<string, int|string>
     */
    private function salesTotals(array $period, ?int $category = null): array
    {
        $report = SalesKpiQuery::execute($this->companyId, $period['date_from'], $period['date_to'], packageCategoryId: $category);

        return Arr::only($report['totals'], self::SALES_FIGURES);
    }

    /**
     * @param  array<string, string>  $period
     * @return array<string, mixed>
     */
    private function business(array $period, ?int $category = null): array
    {
        return BusinessMetricsQuery::execute($period['date_from'] ?? null, $period['date_to'] ?? null, $this->companyId, packageCategoryId: $category);
    }

    /**
     * @param  array<string, string>  $period
     * @return array<string, float>
     */
    private function businessFigures(array $period, ?int $category = null): array
    {
        return array_map(fn (mixed $value): float => (float) $value, Arr::only($this->business($period, $category), self::BUSINESS_FIGURES));
    }

    /**
     * @param  array<string, string>  $period
     * @return array<string, mixed>
     */
    private function sla(array $period): array
    {
        return SlaComplianceQuery::execute($period['date_from'] ?? null, $period['date_to'] ?? null, null, $this->companyId);
    }

    /**
     * @param  array<string, string>  $period
     * @return array<string, mixed>
     */
    private function technicianFigures(array $period): array
    {
        return TechnicianPerformanceQuery::execute($this->technician->id, $period['date_from'] ?? null, $period['date_to'] ?? null, $this->companyId);
    }

    /**
     * @param  array<string, mixed>  $payload  the result of SlaComplianceQuery::execute()
     * @return array<string, array{total: int, compliant: int, rate: float}>
     */
    private function byCategory(array $payload): array
    {
        $rows = [];
        foreach ($payload['by_category'] as $row) {
            $rows[$row['name']] = ['total' => (int) $row['total'], 'compliant' => (int) $row['compliant'], 'rate' => (float) $row['rate']];
        }
        ksort($rows);

        return $rows;
    }

    /** @return array<string, string> */
    private function categoryQuery(?int $packageCategoryId): array
    {
        return $packageCategoryId === null
            ? []
            : ['service_package_category_id' => (string) ServicePackageCategory::withoutCompany()->whereKey($packageCategoryId)->value('public_id')];
    }

    /** @param  array<string, mixed>  $query */
    private function drill(string $report, string $metric, array $query = []): TestResponse
    {
        return $this->actingAs($this->admin)->get(route("admin.reports.{$report}.drill", ['metric' => $metric, ...$query]))->assertOk();
    }

    /**
     * The names a drill list shows, in a stable order.
     *
     * @param  array<string, mixed>  $query
     * @return list<array<string, mixed>>
     */
    private function rows(string $report, string $metric, array $query): array
    {
        $rows = array_map(
            fn (array $row): array => Arr::only($row, ['code', 'number', 'customer', 'package', 'category', 'assignee']),
            $this->drill($report, $metric, $query)->inertiaProps('rows.data'),
        );
        usort($rows, fn (array $a, array $b): int => strcmp((string) ($a['code'] ?? $a['number']), (string) ($b['code'] ?? $b['number'])));

        return $rows;
    }

    /**
     * One column of a drill list keyed by another, to compare rows by name.
     *
     * @param  array<string, string>  $period
     * @return array<string, string>
     */
    private function column(string $report, string $metric, array $period, string $key, string $value, bool $technician = false): array
    {
        $query = [...$period, ...($technician ? ['technician_id' => $this->technician->public_id] : [])];

        return array_column($this->rows($report, $metric, $query), $value, $key);
    }

    /**
     * @param  array<string, string>  $period
     * @return list<string>
     */
    private function codes(string $report, string $metric, array $period, ?int $category = null, bool $technician = false): array
    {
        $query = [...$period, ...$this->categoryQuery($category), ...($technician ? ['technician_id' => $this->technician->public_id] : [])];
        $codes = array_column($this->rows($report, $metric, $query), 'code');
        sort($codes);

        return $codes;
    }

    /**
     * @param  array<string, string>  $period
     * @return list<string>
     */
    private function numbers(string $report, string $metric, array $period, ?int $category = null): array
    {
        $numbers = array_column($this->rows($report, $metric, [...$period, ...$this->categoryQuery($category)]), 'number');
        sort($numbers);

        return $numbers;
    }
}
