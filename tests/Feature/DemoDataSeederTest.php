<?php

namespace Tests\Feature;

use App\Models\Core\Company;
use App\Models\User;
use Database\Seeders\DemoDataSeeder;
use Database\Seeders\DemoUserSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SystemSettingSeeder;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class DemoDataSeederTest extends TestCase
{
    use RefreshDatabase;

    /** Tables every module of the demo company must have rows in. */
    private const MODULE_TABLES = [
        'core' => ['organization_units', 'vehicles', 'employee_profiles', 'employee_evaluations', 'locations', 'customers', 'customer_addresses', 'customer_contacts', 'service_subscriptions', 'document_types', 'number_sequences'],
        'service' => ['bandwidth_profiles', 'speed_profiles', 'sla_tiers', 'service_packages'],
        'inventory' => ['units', 'categories', 'products', 'stocks', 'stock_movements', 'inventory_requests', 'inventory_request_items', 'material_return_cases', 'material_return_lines', 'material_return_events', 'stock_adjustment_requests'],
        'network' => ['network_assets', 'network_asset_installations'],
        'spk' => ['work_orders', 'work_order_items', 'work_order_assignments'],
        'billing' => ['invoices', 'invoice_items', 'payments'],
        'ticketing' => ['ticket_categories', 'tickets', 'ticket_comments'],
    ];

    /** Every state a status column has to show at least once. */
    private const STATUSES = [
        'service_subscriptions' => ['pending', 'active', 'suspended', 'terminated'],
        'invoices' => ['draft', 'sent', 'partial', 'paid', 'overdue', 'cancelled'],
        'work_orders' => ['draft', 'generated', 'assigned', 'in_progress', 'waiting_review', 'completed', 'rejected', 'cancelled'],
        'tickets' => ['open', 'assigned', 'on_progress', 'resolved', 'closed'],
        'inventory_requests' => ['draft', 'submitted', 'approved_reserved', 'preparing', 'ready_for_pickup', 'issued', 'rejected', 'cancelled'],
        'material_return_cases' => ['awaiting_declaration', 'awaiting_receipt', 'correction_required', 'reconciled', 'exception_open', 'resolved'],
        'stock_adjustment_requests' => ['pending', 'approved', 'rejected'],
        'network_assets' => ['available', 'installed', 'maintenance', 'damaged', 'retired'],
        'locations' => ['region', 'area', 'pop', 'rack', 'site'],
    ];

    private int $companyId;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('public');
        $this->seed(RolePermissionSeeder::class);
        app()['cache']->forget('spatie.permission.cache');
        $this->seed(SystemSettingSeeder::class);
        $this->seed(DemoUserSeeder::class);
        $this->companyId = (int) Company::query()->where('code', 'DEMO')->value('id');
    }

    public function test_it_refuses_to_run_without_the_demo_company(): void
    {
        Company::query()->where('code', 'DEMO')->update(['code' => 'OTHER']);

        $this->expectException(RuntimeException::class);

        $this->seed(DemoDataSeeder::class);
    }

    public function test_it_fills_every_module_consistently_and_runs_only_once(): void
    {
        $this->seed(DemoDataSeeder::class);

        $this->assertEveryModuleHasRows();
        $this->assertEveryStatusIsShown();
        $this->assertStockBalancesMatchTheLedger();
        $this->assertInvoicePaymentsMatch();
        $this->assertWorkAndApprovalsFollowTheRules();
        $this->assertHistorySpansSixMonths();
        $this->assertNothingIsDatedInTheFuture();
        $this->assertNotificationsAndMediaExist();

        $before = $this->rowCounts();
        $this->seed(DemoDataSeeder::class);

        $this->assertSame($before, $this->rowCounts(), 'A second run must not add rows.');
    }

    private function assertEveryModuleHasRows(): void
    {
        foreach (self::MODULE_TABLES as $module => $tables) {
            foreach ($tables as $table) {
                $this->assertGreaterThan(0, $this->forCompany($table)->count(), "The {$module} table {$table} has no rows for the demo company.");
            }
        }
    }

    private function assertEveryStatusIsShown(): void
    {
        foreach (self::STATUSES as $table => $statuses) {
            $column = $table === 'locations' ? 'type' : 'status';
            $present = $this->forCompany($table)->distinct()->pluck($column)->all();

            $this->assertSame([], array_values(array_diff($statuses, $present)), "{$table}.{$column} misses a state.");
        }

        $this->assertGreaterThan(0, $this->forCompany('payments')->whereNotNull('cancelled_at')->count(), 'No reversed payment.');
        $this->assertGreaterThan(0, $this->forCompany('tickets')->whereNotNull('sla_breached_notified_at')->count(), 'No ticket with an SLA breach notice.');
        $this->assertGreaterThan(0, $this->forCompany('tickets')->whereColumn('resolved_at', '>', 'sla_deadline')->count(), 'No ticket resolved late.');
        $this->assertGreaterThan(0, $this->forCompany('customers')->whereNull('acquired_by_user_id')->count(), 'Every customer has an acquirer.');
        $this->assertGreaterThanOrEqual(2, $this->forCompany('customers')->whereNotNull('acquired_by_user_id')->distinct()->count('acquired_by_user_id'), 'Sales are credited to one person only.');
        $this->assertGreaterThanOrEqual(30, $this->forCompany('customers')->count());
    }

    /** Every balance is what its movements add up to, and the last movement saw that balance. */
    private function assertStockBalancesMatchTheLedger(): void
    {
        foreach ($this->forCompany('stocks')->get() as $stock) {
            $movements = fn (): Builder => $this->forCompany('stock_movements')
                ->where('product_id', $stock->product_id)
                ->whereRaw('COALESCE(to_location_id, from_location_id) = ?', [$stock->location_id]);

            $onHand = $movements()->whereNotIn('movement_type', ['reserve', 'release'])->sum('quantity');
            $reserved = $movements()->whereIn('movement_type', ['reserve', 'release'])->sum('quantity');
            $label = "product {$stock->product_id} at location {$stock->location_id}";

            $this->assertEqualsWithDelta((float) $stock->quantity, (float) $onHand, 0.001, "Balance of {$label} differs from its movements.");
            $this->assertEqualsWithDelta((float) $stock->reserved_quantity, (float) $reserved, 0.001, "Reservation of {$label} differs from its movements.");

            if ($movements()->exists()) {
                $this->assertEqualsWithDelta((float) $stock->quantity, (float) $movements()->orderByDesc('id')->value('balance_after'), 0.001, "The last movement of {$label} saw another balance.");
            }
        }

        $this->assertSame(0, $this->forCompany('stocks')->where('quantity', '<', 0)->count());
    }

    private function assertInvoicePaymentsMatch(): void
    {
        foreach ($this->forCompany('invoices')->get() as $invoice) {
            $active = $this->forCompany('payments')->where('invoice_id', $invoice->id)->whereNull('cancelled_at')->sum('amount');

            $this->assertEqualsWithDelta((float) $invoice->paid_amount, (float) $active, 0.001, "Invoice {$invoice->number} paid_amount differs from its payments.");

            if ($invoice->status === 'paid') {
                $this->assertGreaterThanOrEqual((float) $invoice->total, (float) $invoice->paid_amount, "Invoice {$invoice->number} is paid but not covered.");
            }

            if (in_array($invoice->status, ['draft', 'cancelled'], true)) {
                $this->assertSame(0.0, (float) $active, "Invoice {$invoice->number} is {$invoice->status} yet has payments.");
            }
        }

        $this->assertSame(0, $this->forCompany('payments')->whereNotNull('cancelled_at')->whereColumn('cancelled_by', 'received_by')->count(), 'A payment was reversed by the person who recorded it.');
    }

    private function assertWorkAndApprovalsFollowTheRules(): void
    {
        $this->assertSame(0, $this->forCompany('work_orders')->where('status', 'completed')->where(fn ($q) => $q->whereColumn('approved_by', 'created_by')->orWhereColumn('approved_by', 'assigned_to'))->count(), 'An SPK was approved by its creator or technician.');
        $this->assertSame(0, $this->forCompany('stock_adjustment_requests')->whereIn('status', ['approved', 'rejected'])->whereColumn('approved_by', 'requested_by')->count(), 'A stock adjustment was reviewed by its requester.');
        $this->assertSame(0, $this->forCompany('invoices')->where('status', 'cancelled')->whereColumn('cancelled_by', 'created_by')->count(), 'An invoice was cancelled by its creator.');

        $installed = $this->forCompany('network_assets')->where('status', 'installed')->pluck('id');
        $open = $this->forCompany('network_asset_installations')->whereNull('removed_at')->whereIn('network_asset_id', $installed)->distinct()->count('network_asset_id');
        $this->assertSame($installed->count(), $open, 'An installed asset has no open installation record.');

        $this->assertSame(0, $this->forCompany('service_subscriptions')->where('status', 'active')->whereNull('activation_date')->count());
    }

    private function assertHistorySpansSixMonths(): void
    {
        $this->assertTrue(Carbon::parse($this->forCompany('customers')->min('created_at'))->lessThanOrEqualTo(now()->subDays(150)), 'Customers do not reach back five months.');
        $this->assertTrue(Carbon::parse($this->forCompany('invoices')->min('issue_date'))->lessThanOrEqualTo(now()->subDays(120)), 'Invoices do not reach back four months.');
        $this->assertTrue(Carbon::parse($this->forCompany('payments')->max('paid_at'))->greaterThanOrEqualTo(now()->subDays(35)), 'No recent payment.');
        $this->assertTrue(Carbon::parse($this->forCompany('stock_movements')->min('created_at'))->lessThanOrEqualTo(now()->subDays(150)), 'The stock ledger does not reach back five months.');
        $this->assertGreaterThan(0, $this->forCompany('work_orders')->where('type', 'installation')->where('status', 'completed')->where('completed_at', '>=', now()->subDays(45))->count());
        $this->assertGreaterThan(0, $this->forCompany('tickets')->where('created_at', '>=', now()->subDays(7))->count());
    }

    /** The story ends at the seeding moment: only deadlines and plans may lie ahead. */
    private function assertNothingIsDatedInTheFuture(): void
    {
        $now = now()->addMinute();

        foreach (['customers' => 'created_at', 'service_subscriptions' => 'activation_date', 'work_orders' => 'completed_at', 'tickets' => 'resolved_at', 'payments' => 'paid_at', 'stock_movements' => 'created_at', 'employee_evaluations' => 'evaluated_at', 'network_asset_installations' => 'installed_at', 'ticket_comments' => 'created_at'] as $table => $column) {
            $this->assertSame(0, $this->forCompany($table)->where($column, '>', $now)->count(), "{$table}.{$column} lies in the future.");
        }

        $this->assertSame(0, $this->forCompany('invoices')->where('issue_date', '>', $now->toDateString())->count(), 'An invoice is issued in the future.');
        $this->assertSame(0, DB::table('activity_log')->where('company_id', $this->companyId)->where('created_at', '>', $now)->count(), 'The audit log lies in the future.');
        $this->assertSame(0, DB::table('notifications')->where('created_at', '>', $now)->count(), 'A notification lies in the future.');
    }

    private function assertNotificationsAndMediaExist(): void
    {
        foreach (['admin', 'manager', 'staff', 'technician', 'customer'] as $role) {
            $user = User::query()->where('email', "{$role}@demo.inbils.test")->firstOrFail();

            $this->assertGreaterThan(0, $user->notifications()->count(), "The demo {$role} has no notification.");
            $this->assertGreaterThan(0, $user->unreadNotifications()->count(), "The demo {$role} has nothing unread.");
        }

        $notifications = fn (): Builder => DB::table('notifications')->where('notifiable_type', User::class);
        $this->assertGreaterThan(0, $notifications()->whereNotNull('read_at')->count());
        $this->assertGreaterThan(0, $notifications()->whereNull('read_at')->count());
        $this->assertGreaterThan(0, DB::table('media')->where('collection_name', 'evidence')->count());
        $this->assertGreaterThan(0, DB::table('media')->where('collection_name', 'documents')->count());
        $this->assertGreaterThan(0, DB::table('activity_log')->where('company_id', $this->companyId)->count());
    }

    /** @return array<string, int> */
    private function rowCounts(): array
    {
        $tables = [...array_merge(...array_values(self::MODULE_TABLES)), 'users', 'company_user', 'notifications', 'media', 'activity_log', 'indonesia_districts', 'indonesia_villages'];
        $counts = [];

        foreach ($tables as $table) {
            $counts[$table] = DB::table($table)->count();
        }

        return $counts;
    }

    private function forCompany(string $table): Builder
    {
        return DB::table($table)->where('company_id', $this->companyId);
    }
}
