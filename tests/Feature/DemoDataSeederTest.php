<?php

namespace Tests\Feature;

use App\Models\Core\Company;
use App\Models\Core\Customer;
use App\Models\User;
use App\Services\Core\CompanyService;
use App\Services\WhatsApp\PhoneNumber;
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
        'ipam' => ['vlans', 'ip_prefixes', 'ip_addresses'],
        'odn' => ['odn_splitters', 'odn_ports'],
        'router' => ['nas_devices', 'network_accounts'],
        'whatsapp' => ['whatsapp_messages'],
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
        $this->assertAddressPlanHasNoConflicts();
        $this->assertFibreTreeIsWiredAndDropsAreFree();
        $this->assertNasAndAccountsAreInertAndExist();
        $this->assertWhatsAppStateIsConsistent();

        $before = $this->rowCounts();
        $this->seed(DemoDataSeeder::class);

        $this->assertSame($before, $this->rowCounts(), 'A second run must not add rows.');
    }

    public function test_people_created_by_the_demo_story_hold_company_roles(): void
    {
        $this->seed(DemoDataSeeder::class);

        $expected = [
            'agus.setiawan@demo.inbils.test' => 'technician',
            'dewi.anggraini@demo.inbils.test' => 'staff',
        ];

        foreach ($expected as $email => $role) {
            $user = User::query()->where('email', $email)->firstOrFail();

            $this->assertSame([$role], CompanyService::runAs(
                $this->companyId,
                fn () => $user->fresh()->roles()->pluck('template_key')->all(),
            ));
        }
    }

    public function test_it_tops_up_a_company_seeded_before_the_network_parts_existed(): void
    {
        $this->seed(DemoDataSeeder::class);
        $before = $this->laneCounts();

        $this->assertGreaterThan(0, $before['ip_addresses']);

        DB::table('whatsapp_messages')->where('dedupe_key', 'like', 'demo:%')->delete();
        DB::table('customers')->update(['whatsapp_opt_in_at' => null, 'whatsapp_opt_out_at' => null, 'whatsapp_opt_in_source' => null, 'whatsapp_opt_in_by' => null]);
        DB::table('network_accounts')->delete();
        DB::table('nas_devices')->delete();
        DB::table('ip_addresses')->delete();
        DB::table('ip_prefixes')->update(['parent_id' => null]);
        DB::table('ip_prefixes')->delete();
        DB::table('vlans')->delete();
        DB::table('odn_splitters')->update(['input_port_id' => null]);
        DB::table('odn_ports')->delete();
        DB::table('odn_splitters')->delete();

        $this->seed(DemoDataSeeder::class);

        $this->assertSame($before, $this->laneCounts(), 'A run on a company without the network parts must add exactly them.');
    }

    /** @return array<string, int> */
    private function laneCounts(): array
    {
        $counts = [];

        foreach (['vlans', 'ip_prefixes', 'ip_addresses', 'odn_splitters', 'odn_ports', 'nas_devices', 'network_accounts', 'whatsapp_messages'] as $table) {
            $counts[$table] = $this->forCompany($table)->count();
        }

        $counts['consenting'] = $this->forCompany('customers')->whereNotNull('whatsapp_opt_in_at')->count();
        $counts['drops'] = $this->forCompany('odn_ports')->whereNotNull('subscription_id')->count();

        return $counts;
    }

    private function assertAddressPlanHasNoConflicts(): void
    {
        $this->assertSame(0, $this->forCompany('ip_addresses')->select('address_cmp')->groupBy('address_cmp')->havingRaw('COUNT(*) > 1')->get()->count(), 'An address is registered twice.');
        $this->assertSame(0, $this->forCompany('ip_addresses')->whereNotNull('subscription_id')->select('subscription_id', 'family')->groupBy('subscription_id', 'family')->havingRaw('COUNT(*) > 1')->get()->count(), 'A subscription holds two addresses of one family.');
        $this->assertSame(0, $this->forCompany('ip_addresses')->whereNull('ip_prefix_id')->count(), 'An address lies outside every registered prefix.');

        foreach (['customer_static', 'customer_dynamic', 'infrastructure', 'container'] as $usage) {
            $this->assertGreaterThan(0, $this->forCompany('ip_prefixes')->where('usage', $usage)->count(), "No {$usage} prefix.");
        }

        $pops = $this->forCompany('locations')->where('type', 'pop')->where('code', 'like', 'POP-BDG-%')->count();
        $this->assertSame($pops, $this->forCompany('ip_prefixes')->where('usage', 'customer_static')->count(), 'Every POP needs a static pool.');
        $this->assertSame($pops, $this->forCompany('ip_prefixes')->where('usage', 'customer_dynamic')->count(), 'Every POP needs a dynamic pool.');
        $this->assertGreaterThan(0, $this->forCompany('ip_addresses')->whereNotNull('network_asset_id')->count(), 'No management address is registered.');
        $this->assertGreaterThan(0, $this->forCompany('ip_addresses')->whereNotNull('subscription_id')->count(), 'No customer holds a static address.');
        $this->assertGreaterThan(0, $this->forCompany('ip_addresses')->where('status', 'reserved')->count(), 'No reserved address (gateway).');

        foreach ($this->forCompany('ip_addresses')->whereNotNull('subscription_id')->get() as $row) {
            $pool = $this->forCompany('ip_prefixes')->where('id', $row->ip_prefix_id)->first();
            $servingPop = $this->forCompany('service_subscriptions')->where('id', $row->subscription_id)->value('serving_pop_id');

            $this->assertSame('customer_static', $pool->usage, "{$row->address} is not in a static pool.");
            $this->assertSame($servingPop, $pool->location_id, "{$row->address} is outside the pool of the serving POP.");
            $this->assertTrue($row->address_cmp >= $pool->range_from && $row->address_cmp <= $pool->range_to, "{$row->address} lies outside {$pool->cidr}.");
        }
    }

    private function assertFibreTreeIsWiredAndDropsAreFree(): void
    {
        foreach ($this->forCompany('network_assets')->where('asset_type', 'olt')->get() as $olt) {
            $this->assertSame(8, $this->forCompany('odn_ports')->where('network_asset_id', $olt->id)->where('kind', 'pon')->count(), "OLT {$olt->name} needs 8 PON ports.");
        }

        foreach ($this->forCompany('network_assets')->whereIn('asset_type', ['odc', 'odp'])->get() as $host) {
            $splitters = $this->forCompany('odn_splitters')->where('network_asset_id', $host->id)->get();

            $this->assertCount(1, $splitters, "{$host->name} needs one splitter.");
            $this->assertSame($host->asset_type === 'odc' ? 4 : 8, (int) $splitters[0]->outputs, "{$host->name} has the wrong splitter ratio.");
            $this->assertNotNull($splitters[0]->input_port_id, "The splitter of {$host->name} is not wired upstream.");
            $this->assertSame($splitters[0]->outputs, $this->forCompany('odn_ports')->where('odn_splitter_id', $splitters[0]->id)->count());
        }

        $this->assertSame(0, $this->forCompany('odn_splitters')->whereIn('input_port_id', $this->forCompany('odn_ports')->whereNotNull('subscription_id')->select('id'))->count(), 'A customer drop feeds a splitter.');
        $this->assertSame(0, $this->forCompany('odn_splitters')->select('input_port_id')->groupBy('input_port_id')->havingRaw('COUNT(*) > 1')->get()->count(), 'A port feeds two splitters.');

        $drops = $this->forCompany('odn_ports')->whereNotNull('subscription_id')->get();
        $this->assertGreaterThan(0, $drops->count(), 'No drop was seeded.');
        $this->assertSame($drops->count(), $drops->pluck('subscription_id')->unique()->count(), 'A subscription has two drop ports.');

        foreach ($drops as $drop) {
            $subscription = $this->forCompany('service_subscriptions')->where('id', $drop->subscription_id)->first();
            $popPath = $this->forCompany('locations')->where('id', $subscription->serving_pop_id)->value('path');
            $odp = $this->forCompany('network_assets')->where('id', $drop->network_asset_id)->first();
            $odpPath = $this->forCompany('locations')->where('id', $odp->location_id)->value('path');

            $this->assertSame('odp', $odp->asset_type, 'A drop is not on an ODP.');
            $this->assertContains($subscription->status, ['active', 'suspended'], 'A drop belongs to a subscription that is not in service.');
            $this->assertStringStartsWith($popPath.' > ', $odpPath, "The drop of {$subscription->code} is not under its serving POP.");
        }
    }

    private function assertNasAndAccountsAreInertAndExist(): void
    {
        $this->assertSame(0, $this->forCompany('company_integrations')->where('provider', 'radius')->count(), 'The demo company must have no RADIUS integration.');
        $this->assertSame(0, $this->forCompany('nas_devices')->whereNotNull('radius_synced_at')->count(), 'A demo NAS reached RADIUS.');
        $this->assertSame(0, $this->forCompany('nas_devices')->whereNull('nasname')->count(), 'A demo NAS has no RADIUS address.');
        $this->assertSame($this->forCompany('network_accounts')->count(), $this->forCompany('network_accounts')->where('sync_status', 'pending')->count(), 'Nothing may have reached RADIUS.');
        $this->assertSame(0, $this->forCompany('network_accounts')->whereNotNull('provisioned_at')->count());
        $this->assertSame(0, $this->forCompany('network_accounts')->where('username', 'not like', '%@%')->count(), 'Every demo username carries its realm.');
        $this->assertGreaterThan(0, $this->forCompany('network_accounts')->whereIn('subscription_id', $this->forCompany('service_subscriptions')->where('status', 'suspended')->select('id'))->count(), 'No suspended customer has an account.');
    }

    private function assertWhatsAppStateIsConsistent(): void
    {
        $company = Company::query()->findOrFail($this->companyId);
        $this->assertFalse((bool) ($company->settings['whatsapp_enabled'] ?? false), 'The demo company must not have WhatsApp switched on.');

        $consenting = $this->forCompany('customers')->whereNotNull('whatsapp_opt_in_at')->whereNull('whatsapp_opt_out_at')->get();
        $this->assertGreaterThanOrEqual(3, $consenting->count());
        $this->assertGreaterThanOrEqual(3, $consenting->filter(fn ($customer) => PhoneNumber::normalizeIndonesianMobile($customer->phone) !== null && str_starts_with($customer->phone, '0812'))->count(), 'Too few consenting customers have a valid 0812 number.');
        $this->assertGreaterThan(0, $this->forCompany('customers')->whereNotNull('whatsapp_opt_out_at')->count(), 'No customer withdrew consent.');

        $statuses = $this->forCompany('whatsapp_messages')->distinct()->pluck('status')->all();
        $this->assertSame([], array_values(array_diff(['queued', 'sent', 'delivered', 'read', 'failed', 'skipped'], $statuses)), 'The message log misses a status.');

        foreach ($this->forCompany('whatsapp_messages')->get() as $message) {
            $this->assertTrue($message->recipient_hash === null || strlen($message->recipient_hash) === 64, 'A message holds something other than a keyed hash.');
            $this->assertTrue($message->recipient_last4 === null || strlen($message->recipient_last4) === 4);

            if ($message->status === 'skipped') {
                $this->assertNotNull($message->skip_reason, 'A skipped message has no reason.');
            }
        }
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
            // The customer app reads the inbox of the Customer the login belongs to (Api\V1\NotificationController), not the user's own.
            $inbox = $role === 'customer' ? Customer::withoutCompany()->where('user_id', $user->id)->firstOrFail() : $user;

            $this->assertGreaterThan(0, $inbox->notifications()->count(), "The demo {$role} has no notification.");
            $this->assertGreaterThan(0, $inbox->unreadNotifications()->count(), "The demo {$role} has nothing unread.");
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
