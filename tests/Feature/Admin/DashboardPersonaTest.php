<?php

namespace Tests\Feature\Admin;

use App\Models\Core\Company;
use App\Models\User;
use App\Queries\Admin\DashboardAdminStatsQuery;
use App\Queries\Admin\DashboardQuery;
use App\Services\Core\AuditService;
use App\Support\PublicId;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;
use Tests\Traits\BuildsDashboardFixtures;

/**
 * Persona dashboard (lane p2-persona-dashboard): which sections each capability set sees,
 * what is absent (not zero) for everyone else, and that every card opens its destination.
 */
class DashboardPersonaTest extends TestCase
{
    use BuildsDashboardFixtures;
    use RefreshDatabase;

    private const SECTION_KEYS = ['key', 'group', 'title', 'count', 'href', 'severity'];

    private const LEGACY_PROPS = ['userCount', 'roleCount', 'activeUserCount', 'permissionCount', 'recentActivity', 'modules'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedDashboardRoles();
    }

    /** @return array<string, array{0: string, 1: list<string>}> */
    public static function roleSections(): array
    {
        return [
            'admin' => ['admin', [
                'dispatch_unassigned', 'sla_breached', 'sla_due_soon', 'customer_replied', 'approvals',
                'warehouse_requests', 'return_queue', 'low_stock',
                'overdue_invoices', 'invoices_due_soon', 'online_payment_exceptions', 'draft_invoices', 'online_payment_pending',
            ]],
            'manager' => ['manager', [
                'dispatch_unassigned', 'sla_breached', 'sla_due_soon', 'customer_replied', 'approvals',
                'warehouse_requests', 'return_queue', 'low_stock',
                'overdue_invoices', 'invoices_due_soon', 'online_payment_exceptions', 'draft_invoices', 'online_payment_pending',
            ]],
            // Operator persona: it prepares and issues but cannot approve, so no approvals card.
            'staff' => ['staff', [
                'sla_breached', 'sla_due_soon', 'customer_replied',
                'warehouse_requests', 'return_queue', 'low_stock',
                'overdue_invoices', 'invoices_due_soon', 'online_payment_exceptions', 'draft_invoices', 'online_payment_pending',
            ]],
            // The technician's own web dashboard is closed (app-only); the role keeps its permission set.
            'technician' => ['technician', ['sla_breached', 'sla_due_soon', 'customer_replied', 'low_stock']],
        ];
    }

    /** @param  list<string>  $expected */
    #[DataProvider('roleSections')]
    public function test_each_seeded_role_sees_only_the_sections_its_permissions_open(string $role, array $expected): void
    {
        $user = $this->userWithRole($this->makeCompany(), $role);

        $response = $this->dashboard($user)->assertOk();

        $this->assertSame($expected, $this->sectionKeys($response), $role);
        $response->assertInertia(fn (Assert $page) => $page->component('Admin/Dashboard/Index'));
    }

    /** @param  list<string>  $expected */
    #[DataProvider('roleSections')]
    public function test_my_work_leads_the_operations_group_once_the_actor_has_an_active_employee_profile(string $role, array $expected): void
    {
        $user = $this->userWithRole($this->makeCompany(), $role);
        $this->activeEmployeeProfile($user);

        $keys = $this->sectionKeys($this->dashboard($user)->assertOk());

        $this->assertSame(['my_work', ...$expected], $keys, $role);
    }

    public function test_my_work_is_absent_not_zero_without_an_active_employee_profile(): void
    {
        $technician = $this->userWithRole($this->makeCompany(), 'technician');

        $this->assertNotContains('my_work', $this->sectionKeys($this->dashboard($technician)));

        $profile = $this->activeEmployeeProfile($technician, 'inactive');
        $this->assertNotContains('my_work', $this->sectionKeys($this->dashboard($technician)));

        $profile->update(['status' => 'active']);
        $this->assertContains('my_work', $this->sectionKeys($this->dashboard($technician)));
    }

    public function test_sections_follow_the_capability_group_order(): void
    {
        $admin = $this->userWithRole($this->makeCompany(), 'admin');
        $this->activeEmployeeProfile($admin);

        $this->assertSame(
            [
                'operations', 'operations', 'operations', 'operations', 'operations',
                'approvals',
                'warehouse', 'warehouse', 'warehouse',
                'finance', 'finance', 'finance', 'finance', 'finance',
            ],
            array_column($this->sections($this->dashboard($admin)), 'group'),
        );
    }

    public function test_section_titles_are_indonesian(): void
    {
        $admin = $this->userWithRole($this->makeCompany(), 'admin');
        $this->activeEmployeeProfile($admin);

        $this->assertSame([
            'my_work' => 'Pekerjaan saya',
            'dispatch_unassigned' => 'Belum ditugaskan',
            'sla_breached' => 'SLA terlampaui',
            'sla_due_soon' => 'Hampir terlampaui (≤ 30 menit)',
            'customer_replied' => 'Pelanggan membalas',
            'approvals' => 'Menunggu persetujuan saya',
            'warehouse_requests' => 'Antrean permintaan gudang',
            'return_queue' => 'Antrean retur material',
            'low_stock' => 'Stok di bawah minimum',
            'overdue_invoices' => 'Tagihan jatuh tempo',
            'invoices_due_soon' => 'Jatuh tempo ≤ 3 hari',
            'online_payment_exceptions' => 'Pembayaran online bermasalah',
            'draft_invoices' => 'Tagihan draf menunggu dikirim',
            'online_payment_pending' => 'Pembayaran online tertunda > 1 jam',
        ], array_column($this->sections($this->dashboard($admin)), 'title', 'key'));
    }

    public function test_a_user_without_a_company_is_refused_in_indonesian(): void
    {
        $user = User::factory()->create(['company_id' => null, 'is_active' => true, 'email_verified_at' => now()]);
        $request = Request::create('/admin/dashboard');
        $request->setUserResolver(fn (): User => $user);

        foreach ([fn () => DashboardQuery::sections($request), fn () => DashboardAdminStatsQuery::stats($user)] as $call) {
            try {
                $call();
                $this->fail('a user without a company must be refused');
            } catch (HttpException $exception) {
                $this->assertSame(403, $exception->getStatusCode());
                $this->assertSame('Konteks perusahaan diperlukan.', $exception->getMessage());
            }
        }
    }

    public function test_every_section_carries_the_documented_base_shape_and_an_absolute_href(): void
    {
        $manager = $this->userWithRole($this->makeCompany(), 'manager');

        foreach ($this->sections($this->dashboard($manager)) as $section) {
            foreach (self::SECTION_KEYS as $key) {
                $this->assertArrayHasKey($key, $section, $section['key']);
            }
            $this->assertIsInt($section['count'], $section['key']);
            $this->assertContains($section['severity'], ['neutral', 'info', 'warning', 'danger'], $section['key']);
            $this->assertStringStartsWith('http', $section['href'], $section['key']);
            $this->assertNotSame('', $section['title'], $section['key']);
        }
    }

    public function test_capable_actor_with_nothing_pending_gets_zero_counts_with_neutral_severity(): void
    {
        $manager = $this->userWithRole($this->makeCompany(), 'manager');

        foreach ($this->sections($this->dashboard($manager)) as $section) {
            $this->assertSame(0, $section['count'], $section['key']);
            $this->assertSame('neutral', $section['severity'], $section['key']);
        }
    }

    /** @return array<string, array{0: list<string>, 1: list<string>}> */
    public static function ticketCapabilities(): array
    {
        return [
            'ticket.view alone opens no card' => [['ticket.view'], []],
            'an SLA report reader' => [['ticket.view', 'report.sla.view'], ['sla_breached', 'sla_due_soon', 'customer_replied']],
            'a ticket starter' => [['ticket.view', 'ticket.start'], ['sla_breached', 'sla_due_soon', 'customer_replied']],
            'a ticket reassigner also dispatches' => [['ticket.view', 'ticket.reassign'], ['dispatch_unassigned', 'sla_breached', 'sla_due_soon', 'customer_replied']],
            'SLA reports without ticket.view open nothing' => [['report.sla.view'], []],
        ];
    }

    /**
     * @param  list<string>  $permissions
     * @param  list<string>  $expected
     */
    #[DataProvider('ticketCapabilities')]
    public function test_the_ticket_queue_cards_need_a_ticket_capability_beyond_ticket_view(array $permissions, array $expected): void
    {
        $user = $this->userWithPermissions($this->makeCompany(), ['dashboard.view', ...$permissions]);

        $this->assertSame($expected, $this->sectionKeys($this->dashboard($user)->assertOk()));
    }

    /** @return array<string, array{0: list<string>, 1: list<string>}> */
    public static function billingCapabilities(): array
    {
        return [
            'billing.view alone only reads what is overdue or due soon' => [['billing.view'], ['overdue_invoices', 'invoices_due_soon']],
            'recording payments without billing.view opens nothing' => [['billing.payment.record'], []],
            'a payment recorder who can read billing settles online payments' => [
                ['billing.view', 'billing.payment.record'],
                ['overdue_invoices', 'invoices_due_soon', 'online_payment_exceptions', 'online_payment_pending'],
            ],
            'a sender without payment recording sees drafts, not online payments' => [
                ['billing.view', 'billing.send'],
                ['overdue_invoices', 'invoices_due_soon', 'draft_invoices'],
            ],
            'both permissions see every finance card' => [
                ['billing.view', 'billing.send', 'billing.payment.record'],
                ['overdue_invoices', 'invoices_due_soon', 'online_payment_exceptions', 'draft_invoices', 'online_payment_pending'],
            ],
        ];
    }

    /**
     * @param  list<string>  $permissions
     * @param  list<string>  $expected
     */
    #[DataProvider('billingCapabilities')]
    public function test_the_online_payment_cards_need_payment_recording_and_the_view_that_opens_their_pages(array $permissions, array $expected): void
    {
        $user = $this->userWithPermissions($this->makeCompany(), ['dashboard.view', ...$permissions]);

        $this->assertSame($expected, $this->sectionKeys($this->dashboard($user)->assertOk()));
    }

    public function test_actor_with_only_dashboard_view_lands_on_an_empty_page_not_a_403(): void
    {
        $user = $this->userWithPermissions($this->makeCompany(), ['dashboard.view']);
        $this->notification($user, ['type' => 'billing_schedule_failed']);

        $this->dashboard($user)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Dashboard/Index')
                ->where('sections', [])
                ->missing('admin')
                ->where('notifications.unreadCount', 1)
                ->has('notifications.data', 1)
            );
    }

    public function test_dashboard_still_requires_dashboard_view(): void
    {
        $user = $this->userWithPermissions($this->makeCompany(), ['ticket.view']);

        $this->dashboard($user)->assertForbidden();
    }

    public function test_dashboard_still_requires_authentication(): void
    {
        $this->get(route('admin.dashboard'))->assertRedirect(route('login'));
    }

    public function test_company_prop_is_kept_for_every_role(): void
    {
        $company = Company::factory()->create(['name' => 'Persona ISP', 'code' => 'PERSONA', 'is_active' => true]);

        foreach (['admin', 'manager', 'staff', 'technician'] as $role) {
            $this->dashboard($this->userWithRole($company, $role))
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->where('company.name', 'Persona ISP')
                    ->where('company.code', 'PERSONA')
                );
        }
    }

    public function test_company_wide_statistics_are_no_longer_top_level_props(): void
    {
        foreach (['admin', 'manager', 'technician'] as $role) {
            $props = $this->dashboard($this->userWithRole($this->makeCompany(), $role))->assertOk()->inertiaProps();

            foreach (self::LEGACY_PROPS as $legacy) {
                $this->assertArrayNotHasKey($legacy, $props, "{$role} must not receive top-level {$legacy}");
            }
        }
    }

    /** @return array<string, array{0: string}> */
    public static function nonAdminRoles(): array
    {
        return ['manager' => ['manager'], 'staff' => ['staff'], 'technician' => ['technician']];
    }

    #[DataProvider('nonAdminRoles')]
    public function test_admin_statistics_are_hidden_from_every_non_admin_role(string $role): void
    {
        $this->dashboard($this->userWithRole($this->makeCompany(), $role))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->missing('admin'));
    }

    /** @return array<string, array{0: string}> */
    public static function adminCapabilities(): array
    {
        return ['users.manage' => ['users.manage'], 'roles.manage' => ['roles.manage'], 'company.manage' => ['company.manage']];
    }

    #[DataProvider('adminCapabilities')]
    public function test_any_single_admin_capability_unlocks_the_admin_block(string $permission): void
    {
        $user = $this->userWithPermissions($this->makeCompany(), ['dashboard.view', $permission]);

        $this->dashboard($user)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('admin', fn (Assert $admin) => $admin
                    ->has('userCount')
                    ->has('activeUserCount')
                    ->has('roleCount')
                    ->has('permissionCount')
                    ->has('recentActivity')
                    ->has('modules')
                )
            );
    }

    public function test_admin_block_counts_only_the_actors_company(): void
    {
        $company = $this->makeCompany();
        $other = $this->makeCompany();
        $admin = $this->userWithRole($company, 'admin');
        $this->userWithRole($company, 'staff');
        $this->userWithRole($company, 'staff', active: false);
        $this->userWithRole($other, 'staff');
        $this->userWithRole($other, 'manager');

        $this->dashboard($admin)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('admin.userCount', 3)
                ->where('admin.activeUserCount', 2)
                ->where('admin.roleCount', 8) // the company's own copies of the eight templates; the other company's do not count
                ->where('admin.permissionCount', Permission::query()->count())
            );
    }

    public function test_admin_block_modules_are_limited_to_the_modules_the_actor_may_open(): void
    {
        $company = $this->makeCompany();
        $this->fixtureCustomer($company);
        $this->invoice($company);
        $this->ticket($company);

        $admin = $this->userWithRole($company, 'admin');
        $hr = $this->userWithPermissions($company, ['dashboard.view', 'users.manage', 'billing.view', 'ticket.view']);
        $minimal = $this->userWithPermissions($company, ['dashboard.view', 'users.manage']);

        $adminModules = collect($this->dashboard($admin)->inertiaProps('admin.modules'))->keyBy('name');
        $this->assertSame(
            ['Pelanggan', 'Layanan', 'Inventaris', 'Aset jaringan', 'SPK', 'Tagihan', 'Tiket'],
            $adminModules->keys()->all(),
        );
        $this->assertSame(1, $adminModules['Pelanggan']['count']);
        $this->assertSame(route('admin.customers.index'), $adminModules['Pelanggan']['href']);

        $hrModules = collect($this->dashboard($hr)->inertiaProps('admin.modules'))->keyBy('name');
        $this->assertSame(['Tagihan', 'Tiket'], $hrModules->keys()->all());
        $this->assertSame(1, $hrModules['Tagihan']['count']);
        $this->assertSame(1, $hrModules['Tiket']['count']);

        $this->assertSame([], $this->dashboard($minimal)->inertiaProps('admin.modules'));
    }

    public function test_admin_recent_activity_lists_only_the_actors_company(): void
    {
        $company = $this->makeCompany();
        $other = $this->makeCompany();
        $admin = $this->userWithRole($company, 'admin');

        // The Activity saving hook only keeps a company it can prove, so the rows carry a company-owned subject.
        AuditService::log('dashboard', 'visible-activity', [], $this->fixtureCreator($company));
        AuditService::log('dashboard', 'foreign-activity', [], $this->fixtureCreator($other));
        // Only the column says whose a row is: a legacy JSON copy that names this company does not make it theirs.
        DB::table('activity_log')->insert([
            'public_id' => PublicId::new(),
            'company_id' => $other->id,
            'log_name' => 'dashboard',
            'description' => 'json-copy-activity',
            'properties' => json_encode(['company_id' => $company->id]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $feed = collect($this->dashboard($admin)->inertiaProps('admin.recentActivity'));

        $this->assertContains('visible-activity', $feed->pluck('description')->all());
        $this->assertNotContains('foreign-activity', $feed->pluck('description')->all());
        $this->assertNotContains('json-copy-activity', $feed->pluck('description')->all());
        $this->assertLessThanOrEqual(10, $feed->count());
        $this->assertSame(['id', 'log_name', 'description', 'text', 'created_at'], array_keys($feed->firstWhere('description', 'visible-activity')));
        $this->assertSame('dashboard: visible-activity', $feed->firstWhere('description', 'visible-activity')['text'], 'a pair the dictionary does not know reads as itself, never as a bare code');
    }

    /** @return array<string, array{0: string}> */
    public static function seededRoles(): array
    {
        return [
            'admin' => ['admin'], 'manager' => ['manager'], 'staff' => ['staff'],
            'technician' => ['technician'],
        ];
    }

    #[DataProvider('seededRoles')]
    public function test_every_card_href_opens_for_the_role_that_sees_it(string $role): void
    {
        $user = $this->userWithRole($this->makeCompany(), $role);
        $this->activeEmployeeProfile($user);

        $sections = $this->sections($this->dashboard($user)->assertOk());
        $this->assertNotEmpty($sections, $role);

        foreach ($sections as $section) {
            $this->actingAs($user)->get($section['href'])->assertOk();
        }
    }

    public function test_inactive_actor_loses_dispatch_and_my_work_but_keeps_capability_gated_cards(): void
    {
        $manager = $this->userWithRole($this->makeCompany(), 'manager', active: false);
        $this->activeEmployeeProfile($manager);

        $keys = $this->sectionKeys($this->dashboard($manager)->assertOk());

        $this->assertNotContains('dispatch_unassigned', $keys);
        $this->assertNotContains('my_work', $keys);
        $this->assertContains('approvals', $keys);
        $this->assertContains('sla_breached', $keys);
    }

    public function test_notification_feed_is_the_latest_five_of_the_actor_with_the_full_unread_count(): void
    {
        $company = $this->makeCompany();
        $teammate = $this->userWithRole($company, 'staff');
        $actor = $this->userWithRole($company, 'staff');

        foreach (range(1, 6) as $minutesAgo) {
            $this->notification($actor, ['type' => 'billing_schedule_failed'], now()->subMinutes($minutesAgo));
        }
        $this->notification($actor, ['type' => 'billing_schedule_failed'], now()->subMinutes(30), now()->subMinutes(29));
        $teammateNotification = $this->notification($teammate, ['type' => 'billing_schedule_failed']);

        $response = $this->dashboard($actor)->assertOk();

        $response->assertInertia(fn (Assert $page) => $page
            ->where('notifications.unreadCount', 6)
            ->has('notifications.data', 5)
            ->has('notifications.data.0', fn (Assert $item) => $item
                ->where('type', 'billing_schedule_failed')
                ->where('title', 'Jadwal penagihan gagal')
                ->where('read_at', null)
                ->where('detail_url', null)
                ->where('subject', null)
                ->has('id')
                ->has('message')
                ->has('created_at')
            )
        );

        $ids = collect($response->inertiaProps('notifications.data'))->pluck('id')->all();
        $this->assertSame($actor->notifications()->latest()->limit(5)->pluck('id')->all(), $ids);
        $this->assertNotContains($teammateNotification->id, $ids);
    }

    public function test_notification_feed_resolves_a_ticket_link_for_a_ticket_the_actor_may_view(): void
    {
        $company = $this->makeCompany();
        $manager = $this->userWithRole($company, 'manager');
        $ticket = $this->ticket($company);
        $this->notification($manager, [
            'type' => 'ticket_sla_breached',
            'company_id' => $company->id,
            'ticket_id' => $ticket->public_id,
            'ticket_code' => $ticket->code,
        ]);

        $this->dashboard($manager)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('notifications.data.0.title', 'SLA tiket terlampaui')
                ->where('notifications.data.0.detail_url', route('admin.tickets.show', $ticket))
            );
    }

    public function test_notification_feed_titles_inventory_and_material_return_types(): void
    {
        $staff = $this->userWithRole($this->makeCompany(), 'staff');
        $types = [
            'inventory_request_approved' => 'Permintaan barang disetujui',
            'inventory_request_ready' => 'Permintaan barang siap diambil',
            'inventory_request_rejected' => 'Permintaan barang ditolak',
            'material_return_declared' => 'Pengembalian material dideklarasikan',
            'material_return_exception' => 'Pengembalian material bermasalah',
        ];
        $minutesAgo = count($types);
        foreach (array_keys($types) as $type) {
            $this->notification($staff, ['type' => $type, 'request_code' => 'REQ-1', 'case_id' => 7], now()->subMinutes($minutesAgo--));
        }

        $titles = collect($this->dashboard($staff)->inertiaProps('notifications.data'))->pluck('title', 'type')->all();

        ksort($types);
        ksort($titles);
        $this->assertSame($types, $titles);
    }

    public function test_the_approvals_card_tells_a_maker_that_their_own_waiting_spk_need_another_admin(): void
    {
        $company = $this->makeCompany();
        $manager = $this->userWithRole($company, 'manager');
        $admin = $this->userWithRole($company, 'admin');
        $this->workOrder($company, ['status' => 'waiting_review', 'created_by' => $manager->id]);
        $this->workOrder($company, ['status' => 'draft', 'created_by' => $manager->id]);
        $this->workOrder($this->makeCompany(), ['status' => 'waiting_review', 'created_by' => $manager->id]);

        $own = collect($this->sections($this->dashboard($manager)))->firstWhere('key', 'approvals');

        $this->assertSame(0, $own['count'], 'the maker-checker rule keeps their own SPK out of the count');
        $this->assertSame(1, $own['maker_blocked']);
        $this->assertSame('neutral', $own['severity']);

        $this->workOrder($company, ['status' => 'waiting_review', 'created_by' => $admin->id]);

        $both = collect($this->sections($this->dashboard($manager)))->firstWhere('key', 'approvals');
        $this->assertSame(1, $both['count']);
        $this->assertSame(1, $both['maker_blocked']);

        $adminCard = collect($this->sections($this->dashboard($admin)))->firstWhere('key', 'approvals');
        $this->assertSame(1, $adminCard['count'], 'the admin sees the manager\'s SPK; their own is the blocked one');
        $this->assertSame(1, $adminCard['maker_blocked']);
    }

    private function dashboard(User $user): TestResponse
    {
        return $this->actingAs($user)->get(route('admin.dashboard'));
    }

    /** @return list<array<string, mixed>> */
    private function sections(TestResponse $response): array
    {
        return array_values($response->inertiaProps('sections') ?? []);
    }

    /** @return list<string> */
    private function sectionKeys(TestResponse $response): array
    {
        return array_column($this->sections($response), 'key');
    }
}
