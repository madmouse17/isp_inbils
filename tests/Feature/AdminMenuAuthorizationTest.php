<?php

namespace Tests\Feature;

use App\Models\Core\Company;
use App\Models\Core\EmployeeProfile;
use App\Models\Core\OrganizationUnit;
use App\Models\User;
use App\Services\Core\CompanyHierarchyService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminMenuAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        app()['cache']->forget('spatie.permission.cache');
    }

    public function test_seeded_inventory_reader_can_open_sidebar_inventory_destinations(): void
    {
        $this->actingAs($this->userWithRole('technician'));

        foreach ([
            route('admin.stocks.index') => 'Admin/Inventory/Stocks/Index',
            route('admin.stock-movements.index') => 'Admin/Inventory/Movements/Index',
            route('admin.inventory.find') => 'Admin/Inventory/Find',
        ] as $uri => $component) {
            $this->get($uri)
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page->component($component));
        }
    }

    public function test_seeded_report_reader_can_open_permitted_report_children_only(): void
    {
        $this->actingAs($this->userWithRole('staff'));

        foreach ([
            route('admin.reports.business') => 'Admin/Reports/Business',
            route('admin.reports.asset') => 'Admin/Reports/Asset',
            route('admin.reports.sla') => 'Admin/Reports/Sla',
            route('admin.reports.stock-card') => 'Admin/Reports/StockCard',
            route('admin.reports.dictionary') => 'Admin/Reports/Dictionary',
        ] as $uri => $component) {
            $this->get($uri)
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page->component($component));
        }

        $this->get(route('admin.reports.technician'))->assertForbidden();

        $this->get(route('admin.reports.audit-log'))->assertForbidden();
    }

    public function test_user_without_inventory_or_report_permissions_gets_403(): void
    {
        $this->actingAs($this->userWithRole('customer'));

        foreach ([
            route('admin.stocks.index'),
            route('admin.stock-movements.index'),
            route('admin.inventory.find'),
            route('admin.reports.index'),
            route('admin.reports.business'),
            route('admin.reports.technician'),
            route('admin.reports.asset'),
            route('admin.reports.sla'),
            route('admin.reports.stock-card'),
            route('admin.reports.dictionary'),
            route('admin.reports.audit-log'),
        ] as $uri) {
            $this->get($uri)->assertForbidden();
        }
    }

    public function test_user_with_users_manage_can_open_filtered_permissions_index(): void
    {
        $this->actingAs($this->userWithRole('admin'));

        $this->get(route('admin.permissions.index', [
            'search' => 'billing',
            'sort' => 'name',
            'direction' => 'desc',
            'per_page' => 25,
        ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Permissions/Index')
                ->where('filters.search', 'billing')
                ->where('filters.sort', 'name')
                ->where('filters.direction', 'desc')
                ->where('filters.per_page', '25'));
    }

    public function test_custom_role_without_users_manage_cannot_open_permissions_index(): void
    {
        $company = Company::factory()->create(['is_active' => true]);
        $user = User::factory()->create([
            'company_id' => $company->id,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
        $role = Role::create(['name' => 'permissions-viewer', 'guard_name' => 'web']);
        $role->syncPermissions([]);
        $user->assignRole($role);

        $this->actingAs($user);

        foreach ([
            route('admin.permissions.index'),
            route('admin.permissions.index', [
                'search' => 'billing',
                'sort' => 'name',
                'direction' => 'desc',
                'per_page' => 25,
            ]),
        ] as $uri) {
            $this->get($uri)->assertForbidden();
        }
    }

    public function test_manager_with_spk_approve_can_open_approval_inbox(): void
    {
        $this->actingAs($this->userWithRole('manager'));

        $this->get(route('admin.approvals.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Admin/Approvals/Index'));
    }

    public function test_technician_without_approval_permission_cannot_open_approval_inbox(): void
    {
        $this->actingAs($this->userWithRole('technician'));

        $this->get(route('admin.approvals.index'))->assertForbidden();
    }

    public function test_admin_can_open_developer_components_gallery_in_local_env(): void
    {
        $this->app['env'] = 'local';
        $this->actingAs($this->userWithRole('admin'));

        $this->get(route('admin.components'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Admin/Components'));
    }

    public function test_admin_can_open_developer_components_gallery_in_testing_env(): void
    {
        $this->actingAs($this->userWithRole('admin'));

        $this->get(route('admin.components'))->assertOk();
    }

    public function test_admin_cannot_open_developer_components_gallery_outside_dev_env(): void
    {
        $this->app['env'] = 'production';
        $this->actingAs($this->userWithRole('admin'));

        $this->get(route('admin.components'))->assertNotFound();
    }

    public function test_non_admin_with_system_setting_cannot_open_developer_components_gallery(): void
    {
        // system.setting is a broad company-wide setting capability; it must
        // NOT grant access to the developer gallery.
        $user = $this->userWithRole('staff');
        $user->givePermissionTo('system.setting');
        $this->app['env'] = 'local';

        $this->actingAs($user)
            ->get(route('admin.components'))
            ->assertForbidden();
    }

    public function test_non_admin_without_developer_components_permission_cannot_open_gallery(): void
    {
        $this->actingAs($this->userWithRole('technician'));

        $this->get(route('admin.components'))->assertForbidden();
    }

    public function test_sidebar_destination_table_matches_the_named_routes_and_real_permissions(): void
    {
        foreach ($this->sidebarDestinations() as $destination) {
            $routeName = $destination['route'];
            $this->assertSame($destination['href'], route($routeName, [], false), $destination['label']);

            foreach ($destination['anyOf'] as $permission) {
                $this->assertTrue(
                    Permission::where('name', $permission)->exists(),
                    "{$destination['label']} names the unknown permission {$permission}",
                );
            }
        }
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function seededRoles(): array
    {
        return [
            'admin' => ['admin', false],
            'manager in an organization' => ['manager', true],
            'staff employee' => ['staff', true],
            'technician employee' => ['technician', true],
            'customer' => ['customer', false],
        ];
    }

    #[DataProvider('seededRoles')]
    public function test_every_sidebar_link_a_seeded_role_sees_opens_and_every_hidden_one_is_denied(
        string $roleName,
        bool $employee,
    ): void {
        $user = $this->userWithRole($roleName);

        if ($employee) {
            // The manager sits in an organization: the technician report also needs one, which
            // the sidebar gate (report.technician) cannot know.
            $this->makeEmployee($user, inOrganization: $roleName === 'manager');
        }

        $this->actingAs($user);
        $context = $this->sidebarContext($this->get(route('admin.dashboard'))->assertOk());

        foreach ($this->sidebarDestinations() as $destination) {
            $shown = $this->sidebarShows($destination, $context);
            $response = $this->get($destination['href']);
            $message = sprintf('%s: %s (%s) is %s', $roleName, $destination['label'], $destination['href'], $shown ? 'shown' : 'hidden');

            if ($shown) {
                $this->assertSame(200, $response->getStatusCode(), $message);
            } elseif ($destination['route'] === 'admin.dispatch.index' && $context['canMyWork']) {
                // Someone who works tickets but cannot dispatch is sent to their own queue.
                $this->assertSame(302, $response->getStatusCode(), $message);
                $response->assertRedirect(route('admin.my-work.index'));
            } else {
                $this->assertSame(403, $response->getStatusCode(), $message);
            }
        }
    }

    public function test_customer_sidebar_offers_dashboard_and_ticketing_only(): void
    {
        $this->actingAs($this->userWithRole('customer'));

        $this->assertSame(['Dashboard', 'Ticketing'], $this->shownSidebarLabels());
    }

    public function test_admin_sidebar_offers_everything_but_my_work_and_consolidation_in_a_single_company(): void
    {
        $this->actingAs($this->userWithRole('admin'));

        $this->assertSame(
            array_values(array_diff(array_column($this->sidebarDestinations(), 'label'), ['Pekerjaan Saya', 'Consolidated Metrics'])),
            $this->shownSidebarLabels(),
        );
    }

    public function test_dashboard_is_denied_without_dashboard_view(): void
    {
        $this->actingAs($this->userWithPermissions(['ticket.view']));

        $this->get(route('admin.dashboard'))->assertForbidden();
        $this->get(route('admin.tickets.index'))->assertOk();
    }

    public function test_inventory_view_alone_opens_the_stock_pages_but_not_inventory_requests(): void
    {
        $this->actingAs($this->userWithPermissions(['inventory.view']));

        $this->get(route('admin.inventory.requests.index'))->assertForbidden();

        foreach ([
            'admin.products.index',
            'admin.categories.index',
            'admin.units.index',
            'admin.stocks.index',
            'admin.inventory.find',
            'admin.stock-movements.index',
        ] as $name) {
            $this->get(route($name))->assertOk();
        }
    }

    public function test_a_request_capability_alone_opens_inventory_requests_only(): void
    {
        foreach (['inventory.request.view.own', 'inventory.request.view.all'] as $permission) {
            $this->actingAs($this->userWithPermissions([$permission]));

            $this->get(route('admin.inventory.requests.index'))->assertOk();
            $this->get(route('admin.products.index'))->assertForbidden();
        }
    }

    public function test_service_manage_alone_opens_every_service_page(): void
    {
        $this->actingAs($this->userWithPermissions(['service.manage']));

        foreach ([
            'admin.service-packages.index',
            'admin.bandwidth-profiles.index',
            'admin.speed-profiles.index',
            'admin.sla-tiers.index',
        ] as $name) {
            $this->get(route($name))->assertOk();
        }
    }

    public function test_dispatch_opens_for_assign_or_reassign_of_an_active_user_only(): void
    {
        foreach (['ticket.assign', 'ticket.reassign', 'spk.assign', 'spk.reassign'] as $permission) {
            $this->actingAs($this->userWithPermissions([$permission]))
                ->get(route('admin.dispatch.index'))
                ->assertOk();
        }

        $this->actingAs($this->userWithPermissions(['ticket.assign'], active: false))
            ->get(route('admin.dispatch.index'))
            ->assertForbidden();
    }

    public function test_system_setting_alone_opens_documents_and_number_sequences_only(): void
    {
        $this->actingAs($this->userWithPermissions(['system.setting']));

        $this->get(route('admin.documents.index'))->assertOk();
        $this->get(route('admin.number-sequences.index'))->assertOk();
        $this->get(route('admin.customers.index'))->assertForbidden();
        $this->get(route('admin.service-packages.index'))->assertForbidden();
    }

    public function test_my_work_opens_only_where_the_server_flags_the_user_eligible(): void
    {
        $technician = $this->userWithRole('technician');
        $this->actingAs($technician);

        $this->get(route('admin.dashboard'))
            ->assertInertia(fn (Assert $page) => $page->where('canMyWork', false));
        $this->get(route('admin.my-work.index'))->assertForbidden();

        $this->makeEmployee($technician);

        $this->get(route('admin.dashboard'))
            ->assertInertia(fn (Assert $page) => $page->where('canMyWork', true));
        $this->get(route('admin.my-work.index'))->assertOk();
    }

    public function test_consolidation_opens_only_where_the_server_flags_a_head_company(): void
    {
        $admin = $this->userWithRole('admin');
        $this->actingAs($admin);

        $this->get(route('admin.dashboard'))
            ->assertInertia(fn (Assert $page) => $page->where('companyHierarchy.can_consolidate', false));
        $this->assertNotContains('Consolidated Metrics', $this->shownSidebarLabels());
        $this->get(route('admin.reports.consolidated'))->assertForbidden();

        app(CompanyHierarchyService::class)->createSubsidiary(
            Company::query()->findOrFail($admin->company_id),
            ['name' => 'Sidebar Subsidiary', 'code' => 'SIDEBAR-SUB'],
        );

        $this->get(route('admin.dashboard'))
            ->assertInertia(fn (Assert $page) => $page->where('companyHierarchy.can_consolidate', true));
        $this->assertContains('Consolidated Metrics', $this->shownSidebarLabels());
        $this->get(route('admin.reports.consolidated'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Admin/Reports/Consolidated'));
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function environments(): array
    {
        return [
            'local' => ['local', true],
            'testing' => ['testing', true],
            'staging' => ['staging', false],
            'production' => ['production', false],
            'e2e' => ['e2e', false],
        ];
    }

    #[DataProvider('environments')]
    public function test_developer_gallery_flag_matches_the_components_route(string $environment, bool $open): void
    {
        $this->app['env'] = $environment;
        $this->actingAs($this->userWithRole('admin'));

        $this->get(route('admin.dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('developerGalleryEnabled', $open));
        $this->get(route('admin.components'))->assertStatus($open ? 200 : 404);
    }

    private function userWithRole(string $roleName): User
    {
        $company = Company::factory()->create(['is_active' => true]);
        $user = User::factory()->create([
            'company_id' => $company->id,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);

        $user->assignRole(Role::where('name', $roleName)->firstOrFail());

        return $user;
    }

    /**
     * @param  list<string>  $permissions
     */
    private function userWithPermissions(array $permissions, bool $active = true): User
    {
        $company = Company::factory()->create(['is_active' => true]);
        $user = User::factory()->create([
            'company_id' => $company->id,
            'email_verified_at' => now(),
            'is_active' => $active,
        ]);
        $role = Role::create(['name' => 'sidebar-'.Str::lower(Str::random(10)), 'guard_name' => 'web']);
        $role->syncPermissions($permissions);
        $user->assignRole($role);

        return $user;
    }

    private function makeEmployee(User $user, bool $inOrganization = false): void
    {
        $organization = $inOrganization
            ? OrganizationUnit::factory()->create([
                'company_id' => $user->company_id,
                'path' => 'OPS',
                'is_active' => true,
            ])
            : null;

        EmployeeProfile::factory()->create([
            'company_id' => $user->company_id,
            'user_id' => $user->id,
            'organization_id' => $organization?->id,
            'status' => 'active',
        ]);
    }

    /**
     * The table of sidebar destinations the nav config is compared with (see navigation.selfcheck.mjs).
     *
     * @return list<array{group: ?string, label: string, href: string, route: string, anyOf: list<string>, flag?: string}>
     */
    private function sidebarDestinations(): array
    {
        return json_decode(
            file_get_contents(__DIR__.'/fixtures/sidebar-destinations.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        )['destinations'];
    }

    /**
     * What the sidebar receives from the server: the shared props of a page the user opened.
     *
     * @return array{permissions: list<string>, canMyWork: bool, canConsolidate: bool, developerGalleryEnabled: bool, isActiveUser: bool}
     */
    private function sidebarContext(TestResponse $page): array
    {
        return [
            'permissions' => $page->inertiaProps('auth.user.permissions'),
            'canMyWork' => $page->inertiaProps('canMyWork') === true,
            'canConsolidate' => $page->inertiaProps('companyHierarchy.can_consolidate') === true,
            'developerGalleryEnabled' => $page->inertiaProps('developerGalleryEnabled') === true,
            'isActiveUser' => $page->inertiaProps('auth.user.is_active') !== false,
        ];
    }

    /**
     * @param  array{anyOf: list<string>, flag?: string}  $destination
     * @param  array{permissions: list<string>, canMyWork: bool, canConsolidate: bool, developerGalleryEnabled: bool, isActiveUser: bool}  $context
     */
    private function sidebarShows(array $destination, array $context): bool
    {
        $permitted = $destination['anyOf'] === []
            || array_intersect($destination['anyOf'], $context['permissions']) !== [];

        return $permitted && (! isset($destination['flag']) || $context[$destination['flag']]);
    }

    /**
     * @return list<string>
     */
    private function shownSidebarLabels(): array
    {
        $context = $this->sidebarContext($this->get(route('admin.dashboard'))->assertOk());

        return array_values(array_column(
            array_filter(
                $this->sidebarDestinations(),
                fn (array $destination): bool => $this->sidebarShows($destination, $context),
            ),
            'label',
        ));
    }
}
