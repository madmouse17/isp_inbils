<?php

namespace Tests\Feature\Admin;

use App\Models\Core\Company;
use App\Models\User;
use App\Services\Core\AuditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
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
                'dispatch_unassigned', 'sla_breached', 'approvals',
                'warehouse_requests', 'return_queue', 'low_stock',
                'overdue_invoices', 'my_requests', 'my_returns',
            ]],
            'manager' => ['manager', [
                'dispatch_unassigned', 'sla_breached', 'approvals',
                'warehouse_requests', 'return_queue', 'low_stock',
                'overdue_invoices',
            ]],
            // Operator persona: it prepares and issues but cannot approve, so no approvals card.
            'staff' => ['staff', [
                'sla_breached',
                'warehouse_requests', 'return_queue', 'low_stock',
                'overdue_invoices',
            ]],
            'technician' => ['technician', ['sla_breached', 'low_stock', 'my_requests', 'my_returns']],
            'customer' => ['customer', ['tickets']],
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

        $this->assertSame($role === 'customer' ? $expected : ['my_work', ...$expected], $keys, $role);
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
            ['operations', 'operations', 'operations', 'approvals', 'warehouse', 'warehouse', 'warehouse', 'finance', 'personal', 'personal'],
            array_column($this->sections($this->dashboard($admin)), 'group'),
        );
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

    public function test_customer_gets_the_minimal_landing_with_only_a_tickets_link(): void
    {
        $company = $this->makeCompany();
        $customer = $this->userWithRole($company, 'customer');
        $this->ticket($company, ['sla_deadline' => now()->subDay()]);

        $response = $this->dashboard($customer)->assertOk();

        $sections = $this->sections($response);
        $this->assertCount(1, $sections);
        $this->assertSame('tickets', $sections[0]['key']);
        $this->assertNull($sections[0]['count']);
        $this->assertSame('neutral', $sections[0]['severity']);
        $this->assertSame(route('admin.tickets.index'), $sections[0]['href']);
        $this->assertArrayNotHasKey('items', $sections[0]);
        $response->assertInertia(fn (Assert $page) => $page->missing('admin'));
    }

    /** @return array<string, array{0: list<string>, 1: list<string>}> */
    public static function ticketCapabilities(): array
    {
        return [
            'ticket.view alone is the customer capability' => [['ticket.view'], ['tickets']],
            'an SLA report reader' => [['ticket.view', 'report.sla.view'], ['sla_breached']],
            'a ticket starter' => [['ticket.view', 'ticket.start'], ['sla_breached']],
            'a ticket reassigner also dispatches' => [['ticket.view', 'ticket.reassign'], ['dispatch_unassigned', 'sla_breached']],
            'SLA reports without ticket.view open nothing' => [['report.sla.view'], []],
        ];
    }

    /**
     * @param  list<string>  $permissions
     * @param  list<string>  $expected
     */
    #[DataProvider('ticketCapabilities')]
    public function test_the_sla_card_needs_a_ticket_capability_beyond_ticket_view(array $permissions, array $expected): void
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

        foreach (['admin', 'manager', 'staff', 'technician', 'customer'] as $role) {
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
        foreach (['admin', 'manager', 'technician', 'customer'] as $role) {
            $props = $this->dashboard($this->userWithRole($this->makeCompany(), $role))->assertOk()->inertiaProps();

            foreach (self::LEGACY_PROPS as $legacy) {
                $this->assertArrayNotHasKey($legacy, $props, "{$role} must not receive top-level {$legacy}");
            }
        }
    }

    /** @return array<string, array{0: string}> */
    public static function nonAdminRoles(): array
    {
        return ['manager' => ['manager'], 'staff' => ['staff'], 'technician' => ['technician'], 'customer' => ['customer']];
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
        $this->userWithRole($company, 'technician', active: false);
        $this->userWithRole($other, 'staff');
        $this->userWithRole($other, 'manager');

        $this->dashboard($admin)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('admin.userCount', 3)
                ->where('admin.activeUserCount', 2)
                ->where('admin.roleCount', 5)
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
            ['Customer', 'Service', 'Inventory', 'Network Asset', 'SPK', 'Billing', 'Ticketing'],
            $adminModules->keys()->all(),
        );
        $this->assertSame(1, $adminModules['Customer']['count']);
        $this->assertSame(route('admin.customers.index'), $adminModules['Customer']['href']);

        $hrModules = collect($this->dashboard($hr)->inertiaProps('admin.modules'))->keyBy('name');
        $this->assertSame(['Billing', 'Ticketing'], $hrModules->keys()->all());
        $this->assertSame(1, $hrModules['Billing']['count']);
        $this->assertSame(1, $hrModules['Ticketing']['count']);

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

        $feed = collect($this->dashboard($admin)->inertiaProps('admin.recentActivity'));

        $this->assertContains('visible-activity', $feed->pluck('description')->all());
        $this->assertNotContains('foreign-activity', $feed->pluck('description')->all());
        $this->assertLessThanOrEqual(10, $feed->count());
        $this->assertSame(['id', 'log_name', 'description', 'created_at'], array_keys($feed->firstWhere('description', 'visible-activity')));
    }

    /** @return array<string, array{0: string}> */
    public static function seededRoles(): array
    {
        return [
            'admin' => ['admin'], 'manager' => ['manager'], 'staff' => ['staff'],
            'technician' => ['technician'], 'customer' => ['customer'],
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
        $actor = $this->userWithRole($company, 'customer');

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
                ->where('title', 'Billing schedule failed')
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
            'ticket_id' => $ticket->id,
            'ticket_code' => $ticket->code,
        ]);

        $this->dashboard($manager)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('notifications.data.0.title', 'Ticket SLA breached')
                ->where('notifications.data.0.detail_url', route('admin.tickets.show', $ticket))
            );
    }

    public function test_notification_feed_titles_inventory_and_material_return_types(): void
    {
        $staff = $this->userWithRole($this->makeCompany(), 'staff');
        $types = [
            'inventory_request_approved' => 'Inventory request approved',
            'inventory_request_ready' => 'Inventory request ready for pickup',
            'inventory_request_rejected' => 'Inventory request rejected',
            'material_return_declared' => 'Material return declared',
            'material_return_exception' => 'Material return exception',
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
