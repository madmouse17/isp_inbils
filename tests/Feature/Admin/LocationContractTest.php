<?php

namespace Tests\Feature\Admin;

use App\Models\Core\Company;
use App\Models\Core\Location;
use App\Models\User;
use App\Policies\LocationPolicy;
use App\Services\Core\CompanyService;
use App\Services\Core\LocationService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Focused feature tests for the Location backend contract (fase 1):
 * - server-side paginator contract (search/type/status/sort allowlist/per_page/page)
 * - coordinate pair invariant (both null or both valid, range inclusive)
 * - tenant fail-closed + Gate on every mutation, company/path untrusted
 * - permission alignment: location.update vs location.move
 * - activity audit: actor + old/new for coordinates/type/is_active, no-op logs nothing
 * - no N+1 on the index list
 */
class LocationContractTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Company $company;

    private Company $otherCompany;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        app()['cache']->forget('spatie.permission.cache');

        $this->company = Company::factory()->create(['is_active' => true]);
        $this->otherCompany = Company::factory()->create(['is_active' => true]);

        $this->admin = User::factory()->create([
            'company_id' => $this->company->id,
            'name' => 'Zzzz Admin',
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
        $this->admin->assignRole('admin');
    }

    private function location(array $overrides = []): Location
    {
        return Location::factory()->create(array_merge([
            'company_id' => $this->company->id,
            'type' => 'region',
        ], $overrides));
    }

    private function userWithPermissions(array $permissions): User
    {
        $role = Role::firstOrCreate(['name' => 'loc-custom-'.uniqid(), 'guard_name' => 'web']);
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        $role->syncPermissions($permissions);

        $user = User::factory()->create([
            'company_id' => $this->company->id,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
        $user->assignRole($role);

        return $user;
    }

    // ------------------------------------------------------------------
    // Server-side list contract
    // ------------------------------------------------------------------

    public function test_index_paginates_company_scoped_with_search_type_status_and_sort(): void
    {
        $this->location(['code' => 'A-1', 'name' => 'Jakarta POP', 'type' => 'pop', 'lat' => -6.2, 'lng' => 106.8]);
        $this->location(['code' => 'A-2', 'name' => 'Jakarta Rack', 'type' => 'rack', 'lat' => -6.2, 'lng' => 106.8]);
        $this->location(['code' => 'A-3', 'name' => 'Bogor Area', 'type' => 'area', 'lat' => -6.5, 'lng' => 106.6, 'is_active' => false]);
        $this->location(['code' => 'X-9', 'name' => 'Other Co', 'type' => 'region', 'company_id' => $this->otherCompany->id]);

        $this->actingAs($this->admin)
            ->get(route('admin.locations.index', [
                'search' => 'Jakarta',
                'type' => 'pop',
                'status' => 'active',
                'sort' => 'code',
                'direction' => 'asc',
                'per_page' => 10,
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Locations/Index')
                ->has('locations.data', 1)
                ->where('locations.data.0.code', 'A-1')
                ->where('locations.data.0.type', 'pop')
                ->where('locations.meta.current_page', 1)
                ->where('locations.meta.total', 1)
                ->where('filters.search', 'Jakarta')
                ->where('filters.type', 'pop')
                ->where('filters.status', 'active')
                ->where('can.create', true)
                ->where('can.update', true)
                ->where('can.move', true)
                ->where('can.delete', true)
            );
    }

    public function test_index_rejects_non_allowlisted_sort_and_per_page(): void
    {
        $this->location(['code' => 'A-1', 'type' => 'region']);
        $this->location(['code' => 'A-2', 'type' => 'region']);
        $this->location(['code' => 'A-3', 'type' => 'region']);
        $this->location(['code' => 'A-4', 'type' => 'region']);
        $this->location(['code' => 'A-5', 'type' => 'region']);
        $this->location(['code' => 'A-6', 'type' => 'region']);
        $this->location(['code' => 'A-7', 'type' => 'region']);
        $this->location(['code' => 'A-8', 'type' => 'region']);
        $this->location(['code' => 'A-9', 'type' => 'region']);
        $this->location(['code' => 'A-10', 'type' => 'region']);
        $this->location(['code' => 'A-11', 'type' => 'region']);

        // sort=company_id is NOT in the allowlist -> falls back to default
        // per_page=999 is NOT in [10,25,50] -> falls back to 10
        $this->actingAs($this->admin)
            ->get(route('admin.locations.index', [
                'sort' => 'company_id',
                'per_page' => 999,
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Locations/Index')
                ->has('locations.data', 10)
                ->where('locations.meta.per_page', 10)
                ->where('locations.meta.total', 11)
            );
    }

    public function test_index_does_not_leak_other_company_locations(): void
    {
        $this->location(['code' => 'OWN-1', 'type' => 'region']);
        $this->location(['code' => 'OTHER-1', 'type' => 'region', 'company_id' => $this->otherCompany->id]);
        $this->location(['code' => 'OTHER-2', 'type' => 'pop', 'company_id' => $this->otherCompany->id, 'lat' => 1.0, 'lng' => 2.0]);

        $this->actingAs($this->admin)
            ->get(route('admin.locations.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Locations/Index')
                ->has('locations.data', 1)
                ->where('locations.data.0.code', 'OWN-1')
                ->where('locations.meta.total', 1)
            );
    }

    public function test_index_bounded_query_no_n_plus_one(): void
    {
        foreach (['A-1', 'A-2', 'A-3', 'A-4', 'A-5'] as $code) {
            $this->location(['code' => $code, 'type' => 'region']);
        }

        $this->actingAs($this->admin);

        $queryCount = 0;
        DB::listen(fn () => $queryCount++);

        $this->get(route('admin.locations.index'))->assertOk();

        // The index renders a paginated list + a nested tree from a single
        // fetch each; it must not issue one query per rendered location.
        $this->assertLessThan(20, $queryCount);
    }

    // ------------------------------------------------------------------
    // Coordinate pair invariant
    // ------------------------------------------------------------------

    public function test_store_rejects_partial_coordinate_pair(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.locations.store'), [
                'code' => 'PARTIAL-1',
                'name' => 'Partial Pair',
                'type' => 'pop',
                'lat' => -6.2,
                'lng' => '',
            ])
            ->assertSessionHasErrors('lat');

        $this->assertDatabaseMissing('locations', ['code' => 'PARTIAL-1']);
    }

    public function test_store_rejects_out_of_range_coordinates(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.locations.store'), [
                'code' => 'RANGE-1',
                'name' => 'Bad Range',
                'type' => 'pop',
                'lat' => 91,
                'lng' => 106,
            ])
            ->assertSessionHasErrors('lat');

        $this->actingAs($this->admin)
            ->post(route('admin.locations.store'), [
                'code' => 'RANGE-2',
                'name' => 'Bad Lng',
                'type' => 'pop',
                'lat' => -6.2,
                'lng' => 181,
            ])
            ->assertSessionHasErrors('lng');
    }

    public function test_store_accepts_boundary_and_null_null_coordinates(): void
    {
        $parent = $this->location([
            'code' => 'REG-BOUND-1',
            'type' => 'region',
        ]);

        $this->actingAs($this->admin)
            ->post(route('admin.locations.store'), [
                'code' => 'BOUND-1',
                'name' => 'Boundary',
                'type' => 'area',
                'parent_id' => $parent->public_id,
                'lat' => 90,
                'lng' => 180,
            ])
            ->assertSessionHasNoErrors();

        $this->actingAs($this->admin)
            ->post(route('admin.locations.store'), [
                'code' => 'NULL-1',
                'name' => 'Legacy',
                'type' => 'region',
                'lat' => null,
                'lng' => null,
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('locations', ['code' => 'NULL-1', 'lat' => null, 'lng' => null]);
    }

    public function test_update_rejects_partial_coordinate_pair_and_keeps_old_values(): void
    {
        $location = $this->location(['code' => 'UP-1', 'type' => 'pop', 'lat' => -6.2, 'lng' => 106.8]);

        $this->actingAs($this->admin)
            ->put(route('admin.locations.update', $location->public_id), [
                'code' => 'UP-1',
                'name' => 'Updated',
                'type' => 'pop',
                'lat' => -6.2,
                'lng' => null,
            ])
            ->assertSessionHasErrors('lat');

        $this->assertDatabaseHas('locations', ['id' => $location->id, 'lng' => '106.8000000']);
    }

    // ------------------------------------------------------------------
    // Tenant fail-closed + Gate + IDOR
    // ------------------------------------------------------------------

    public function test_company_and_path_are_never_trusted_from_payload(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.locations.store'), [
                'code' => 'SPOOF-1',
                'name' => 'Spoof',
                'type' => 'region',
                'company_id' => $this->otherCompany->id,
                'path' => 'HACKED > SPOOF-1',
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('locations', [
            'code' => 'SPOOF-1',
            'company_id' => $this->company->id,
        ]);
        $this->assertDatabaseMissing('locations', [
            'code' => 'SPOOF-1',
            'company_id' => $this->otherCompany->id,
        ]);
    }

    public function test_a_code_containing_the_path_separator_is_refused_so_it_cannot_fake_ancestry(): void
    {
        $location = Location::factory()->create(['company_id' => $this->company->id, 'code' => 'ROOT-1', 'type' => 'region']);

        foreach (['POP-A > X', 'POP-A >'] as $code) {
            $this->actingAs($this->admin)
                ->post(route('admin.locations.store'), ['code' => $code, 'name' => 'Fake child', 'type' => 'region'])
                ->assertSessionHasErrors('code');
            $this->actingAs($this->admin)
                ->put(route('admin.locations.update', $location), ['code' => $code, 'name' => 'Fake child', 'type' => 'region'])
                ->assertSessionHasErrors('code');
        }

        $this->assertSame(0, Location::query()->where('code', 'like', '%>%')->count());
        $this->assertSame('ROOT-1', $location->fresh()->code, 'a refused update leaves the row alone');
    }

    public function test_update_on_other_company_location_returns_404(): void
    {
        $foreign = $this->location([
            'code' => 'FOREIGN-1',
            'type' => 'region',
            'company_id' => $this->otherCompany->id,
        ]);

        $this->actingAs($this->admin)
            ->put(route('admin.locations.update', $foreign->public_id), [
                'code' => 'FOREIGN-1',
                'name' => 'Hacked',
                'type' => 'region',
            ])
            ->assertNotFound();
    }

    public function test_move_on_other_company_location_returns_404(): void
    {
        $foreign = $this->location([
            'code' => 'FOREIGN-2',
            'type' => 'region',
            'company_id' => $this->otherCompany->id,
        ]);

        $this->actingAs($this->admin)
            ->post(route('admin.locations.move', $foreign->public_id), ['new_parent_id' => 1])
            ->assertNotFound();
    }

    public function test_destroy_on_other_company_location_returns_404(): void
    {
        $foreign = $this->location([
            'code' => 'FOREIGN-3',
            'type' => 'region',
            'company_id' => $this->otherCompany->id,
        ]);

        $this->actingAs($this->admin)
            ->delete(route('admin.locations.destroy', $foreign->public_id))
            ->assertNotFound();
    }

    public function test_move_rejects_cross_company_parent(): void
    {
        $own = $this->location(['code' => 'OWN-CHILD', 'type' => 'area']);
        $foreignParent = $this->location([
            'code' => 'FOREIGN-PARENT',
            'type' => 'region',
            'company_id' => $this->otherCompany->id,
        ]);

        $this->actingAs($this->admin)
            ->post(route('admin.locations.move', $own->public_id), ['new_parent_id' => $foreignParent->public_id])
            ->assertSessionHasErrors('new_parent_id');

        $this->assertDatabaseHas('locations', ['id' => $own->id, 'parent_id' => null]);
    }

    // ------------------------------------------------------------------
    // RBAC / permission alignment (location.move)
    // ------------------------------------------------------------------

    public function test_user_with_update_but_without_move_cannot_move(): void
    {
        $user = $this->userWithPermissions(['location.view', 'location.update']);
        $region = $this->location(['code' => 'REG-1', 'type' => 'region']);
        $child = $this->location(['code' => 'AREA-1', 'type' => 'area', 'parent_id' => $region->id]);

        $this->actingAs($user)
            ->post(route('admin.locations.move', $child->public_id), ['new_parent_id' => $region->public_id])
            ->assertForbidden();
    }

    public function test_user_with_move_permission_can_move(): void
    {
        $user = $this->userWithPermissions(['location.view', 'location.move']);
        $region = $this->location(['code' => 'REG-MV', 'type' => 'region']);
        $child = $this->location(['code' => 'AREA-MV', 'type' => 'area', 'parent_id' => null]);

        $this->actingAs($user)
            ->post(route('admin.locations.move', $child->public_id), ['new_parent_id' => $region->public_id])
            ->assertRedirect();

        $this->assertDatabaseHas('locations', ['id' => $child->id, 'parent_id' => $region->id]);
    }

    public function test_view_only_user_is_forbidden_on_store_update_move_destroy(): void
    {
        $user = $this->userWithPermissions(['location.view']);
        $own = $this->location(['code' => 'VIEW-1', 'type' => 'region']);

        $this->actingAs($user)
            ->post(route('admin.locations.store'), ['code' => 'NOPE', 'name' => 'Nope', 'type' => 'region'])
            ->assertForbidden();

        $this->actingAs($user)
            ->put(route('admin.locations.update', $own->public_id), ['code' => 'VIEW-1', 'name' => 'Nope', 'type' => 'region'])
            ->assertForbidden();

        $this->actingAs($user)
            ->post(route('admin.locations.move', $own->public_id), ['new_parent_id' => 1])
            ->assertForbidden();

        $this->actingAs($user)
            ->delete(route('admin.locations.destroy', $own->public_id))
            ->assertForbidden();
    }

    public function test_unauthenticated_request_redirects_to_login(): void
    {
        $this->get(route('admin.locations.index'))->assertRedirect(route('login'));
    }

    // ------------------------------------------------------------------
    // Activity audit
    // ------------------------------------------------------------------

    public function test_create_activity_records_actor_and_location_scope(): void
    {
        $region = $this->location([
            'code' => 'REG-AUDIT-1',
            'type' => 'region',
        ]);
        $area = $this->location([
            'code' => 'AREA-AUDIT-1',
            'type' => 'area',
            'parent_id' => $region->id,
        ]);

        $this->actingAs($this->admin)
            ->post(route('admin.locations.store'), [
                'code' => 'AUDIT-1',
                'name' => 'Audited',
                'type' => 'pop',
                'parent_id' => $area->public_id,
                'lat' => -6.2,
                'lng' => 106.8,
                'is_active' => true,
            ])
            ->assertRedirect();

        $location = Location::query()->where('code', 'AUDIT-1')->firstOrFail();
        $activity = Activity::query()
            ->where('log_name', 'location')
            ->where('subject_type', Location::class)
            ->where('subject_id', $location->id)
            ->latest('id')
            ->first();

        $this->assertNotNull($activity);
        $this->assertSame($this->admin->id, $activity->causer_id);
        $this->assertSame('created', $activity->description);
        $this->assertSame($this->company->id, $activity->company_id);
        $this->assertSame($this->company->id, $activity->subject->company_id);
        $this->assertSame((int) $activity->subject->company_id, (int) $activity->company_id);
    }

    public function test_update_activity_records_actor_and_old_new_for_coordinates_type_status(): void
    {
        $region = $this->location([
            'code' => 'REG-AUDIT-2',
            'type' => 'region',
        ]);
        $location = $this->location([
            'code' => 'AUDIT-2',
            'type' => 'area',
            'parent_id' => $region->id,
            'lat' => -6.2,
            'lng' => 106.8,
            'is_active' => true,
        ]);
        $parentArea = $this->location([
            'code' => 'AREA-AUDIT-2',
            'type' => 'area',
            'parent_id' => $region->id,
        ]);

        $this->actingAs($this->admin)
            ->put(route('admin.locations.update', $location->public_id), [
                'code' => 'AUDIT-2',
                'name' => 'Audited',
                'type' => 'pop',
                'parent_id' => $parentArea->public_id,
                'lat' => -6.3,
                'lng' => 106.9,
                'is_active' => false,
            ])
            ->assertRedirect();

        $activity = Activity::query()
            ->where('log_name', 'location')
            ->where('subject_type', Location::class)
            ->where('subject_id', $location->id)
            ->where('description', 'updated')
            ->latest('id')
            ->first();

        $this->assertNotNull($activity);
        $this->assertSame($this->admin->id, $activity->causer_id);
        $this->assertSame($this->company->id, $activity->company_id);
        $this->assertSame($this->company->id, $activity->subject->company_id);
        $this->assertSame((int) $activity->subject->company_id, (int) $activity->company_id);
        $props = $activity->properties->toArray();

        $this->assertArrayHasKey('old', $props);
        $this->assertArrayHasKey('new', $props);
        $this->assertSame('area', $props['old']['type'] ?? null);
        $this->assertSame('pop', $props['new']['type'] ?? null);
        $this->assertSame(true, $props['old']['is_active'] ?? 'missing');
        $this->assertSame(false, $props['new']['is_active'] ?? 'missing');
        $this->assertSame('-6.2000000', $props['old']['lat'] ?? null);
        $this->assertSame('-6.3000000', $props['new']['lat'] ?? null);
        $this->assertSame('106.8000000', $props['old']['lng'] ?? null);
        $this->assertSame('106.9000000', $props['new']['lng'] ?? null);
    }

    public function test_delete_activity_records_actor_and_server_authoritative_location_scope(): void
    {
        config(['activitylog.subject_returns_soft_deleted_models' => true]);

        $location = $this->location([
            'code' => 'AUDIT-DELETE',
            'type' => 'region',
        ]);

        $this->actingAs($this->admin)
            ->delete(route('admin.locations.destroy', $location->public_id))
            ->assertRedirect();

        $activity = Activity::query()
            ->where('log_name', 'location')
            ->where('subject_type', Location::class)
            ->where('subject_id', $location->id)
            ->where('description', 'deleted')
            ->latest('id')
            ->first();

        $this->assertNotNull($activity);
        $this->assertSame($this->admin->id, $activity->causer_id);
        $this->assertSame('deleted', $activity->description);
        $this->assertNotNull($activity->subject);
        $this->assertSame(
            (int) $activity->subject->company_id,
            (int) $activity->company_id
        );
        $this->assertSame((int) $this->company->id, (int) $activity->subject->company_id);
    }

    public function test_noop_update_does_not_create_activity_event(): void
    {
        $location = $this->location(['code' => 'AUDIT-3', 'type' => 'region']);

        $this->actingAs($this->admin)
            ->put(route('admin.locations.update', $location->public_id), [
                'code' => 'AUDIT-3',
                'name' => $location->name,
                'type' => 'region',
                'is_active' => true,
            ])
            ->assertRedirect();

        $this->assertFalse(
            Activity::query()
                ->where('log_name', 'location')
                ->where('subject_type', Location::class)
                ->where('subject_id', $location->id)
                ->where('description', 'updated')
                ->exists()
        );
    }

    // ------------------------------------------------------------------
    // Tenant policy/service defense-in-depth & query hardening regression
    // ------------------------------------------------------------------

    public function test_policy_fails_closed_when_user_or_location_company_is_null_or_different(): void
    {
        $policy = new LocationPolicy();

        $userWithCompany = $this->admin;
        $locSame = $this->location(['code' => 'POL-SAME', 'type' => 'region']);
        $locOther = $this->location(['code' => 'POL-OTHER', 'type' => 'region', 'company_id' => $this->otherCompany->id]);

        $userNoCompany = User::factory()->create([
            'company_id' => null,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
        $userNoCompany->assignRole('admin');

        $this->assertTrue($policy->view($userWithCompany, $locSame));
        $this->assertFalse($policy->view($userWithCompany, $locOther));
        $this->assertFalse($policy->view($userNoCompany, $locSame));

        $this->assertTrue($policy->update($userWithCompany, $locSame));
        $this->assertFalse($policy->update($userWithCompany, $locOther));
        $this->assertFalse($policy->update($userNoCompany, $locSame));

        $this->assertTrue($policy->move($userWithCompany, $locSame));
        $this->assertFalse($policy->move($userWithCompany, $locOther));
        $this->assertFalse($policy->move($userNoCompany, $locSame));

        $this->assertTrue($policy->delete($userWithCompany, $locSame));
        $this->assertFalse($policy->delete($userWithCompany, $locOther));
        $this->assertFalse($policy->delete($userNoCompany, $locSame));
    }

    public function test_update_with_blank_non_region_parent_retains_existing_parent_and_recomputes_path(): void
    {
        $region = $this->location(['code' => 'REG-BLANK', 'type' => 'region']);
        $child = $this->location([
            'code' => 'AREA-BLANK',
            'type' => 'area',
            'parent_id' => $region->id,
            'path' => 'stale path',
        ]);

        $this->actingAs($this->admin)
            ->put(route('admin.locations.update', $child->public_id), [
                'code' => $child->code,
                'name' => 'Updated child',
                'type' => 'area',
                'parent_id' => '',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('locations', [
            'id' => $child->id,
            'parent_id' => $region->id,
            'path' => $region->path.' > '.$child->code,
        ]);
    }

    public function test_region_transition_with_blank_parent_clears_parent_and_recomputes_path(): void
    {
        $region = $this->location(['code' => 'REG-CLEAR', 'type' => 'region']);
        $child = $this->location([
            'code' => 'AREA-CLEAR',
            'type' => 'area',
            'parent_id' => $region->id,
        ]);

        $this->actingAs($this->admin)
            ->put(route('admin.locations.update', $child->public_id), [
                'code' => $child->code,
                'name' => $child->name,
                'type' => 'region',
                'parent_id' => '',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('locations', [
            'id' => $child->id,
            'parent_id' => null,
            'path' => 'AREA-CLEAR',
        ]);
    }

    public function test_non_region_update_without_current_parent_fails_closed_without_writing(): void
    {
        $location = $this->location([
            'code' => 'ORPHAN-UPDATE',
            'type' => 'region',
            'path' => 'ORPHAN-UPDATE',
        ]);

        $this->actingAs($this->admin)
            ->put(route('admin.locations.update', $location->public_id), [
                'code' => $location->code,
                'name' => 'Must not persist',
                'type' => 'area',
                'parent_id' => '',
            ])
            ->assertSessionHasErrors('parent_id');

        $this->assertDatabaseHas('locations', [
            'id' => $location->id,
            'name' => $location->name,
            'type' => 'region',
            'parent_id' => null,
            'path' => 'ORPHAN-UPDATE',
        ]);
    }

    public function test_service_update_with_blank_parent_retains_valid_parent(): void
    {
        CompanyService::useCompanyForTests($this->company->id);

        $region = $this->location(['code' => 'REG-SERVICE', 'type' => 'region']);
        $child = $this->location([
            'code' => 'AREA-SERVICE',
            'type' => 'area',
            'parent_id' => $region->id,
            'path' => 'stale path',
        ]);

        $updated = LocationService::update($child, [
            'name' => 'Service update',
            'type' => 'area',
            'parent_id' => '',
        ]);

        $this->assertSame($region->id, $updated->parent_id);
        $this->assertSame($region->path.' > '.$child->code, $updated->path);
        $this->assertDatabaseHas('locations', [
            'id' => $child->id,
            'parent_id' => $region->id,
            'path' => $region->path.' > '.$child->code,
        ]);
    }

    public function test_service_update_non_region_without_parent_fails_closed_without_writing(): void
    {
        CompanyService::useCompanyForTests($this->company->id);

        $location = $this->location([
            'code' => 'ORPHAN-SERVICE',
            'type' => 'region',
            'path' => 'ORPHAN-SERVICE',
        ]);

        try {
            LocationService::update($location, [
                'name' => 'Must not persist',
                'type' => 'area',
                'parent_id' => '',
            ]);
            $this->fail('Expected validation failure for a non-region location without a parent.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('parent_id', $e->errors());
        }

        $this->assertDatabaseHas('locations', [
            'id' => $location->id,
            'name' => $location->name,
            'type' => 'region',
            'parent_id' => null,
            'path' => 'ORPHAN-SERVICE',
        ]);
    }

    public function test_service_update_region_transition_with_blank_parent_clears_parent_and_path(): void
    {
        CompanyService::useCompanyForTests($this->company->id);

        $region = $this->location(['code' => 'REG-SERVICE-CLEAR', 'type' => 'region']);
        $child = $this->location([
            'code' => 'AREA-SERVICE-CLEAR',
            'type' => 'area',
            'parent_id' => $region->id,
        ]);

        $updated = LocationService::update($child, [
            'type' => 'region',
            'parent_id' => '',
        ]);

        $this->assertNull($updated->parent_id);
        $this->assertSame('AREA-SERVICE-CLEAR', $updated->path);
        $this->assertDatabaseHas('locations', [
            'id' => $child->id,
            'parent_id' => null,
            'path' => 'AREA-SERVICE-CLEAR',
        ]);
    }

    public function test_service_update_rejects_parent_type_transition_that_would_invalidate_direct_child_without_mutation(): void
    {
        CompanyService::useCompanyForTests($this->company->id);

        $region = $this->location(['code' => 'TRANSITION-REGION', 'type' => 'region']);
        $area = $this->location([
            'code' => 'TRANSITION-AREA',
            'name' => 'Original Area',
            'type' => 'area',
            'parent_id' => $region->id,
            'address' => 'Original area address',
        ]);
        $pop = $this->location([
            'code' => 'TRANSITION-POP',
            'name' => 'Original POP',
            'type' => 'pop',
            'parent_id' => $area->id,
            'address' => 'Original POP address',
        ]);
        $areaPath = $area->path;
        $popPath = $pop->path;

        try {
            LocationService::update($area, [
                'name' => 'Must not persist',
                'type' => 'region',
                'parent_id' => '',
            ]);
            $this->fail('Expected validation failure for an incompatible parent type transition.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('type', $e->errors());
        }

        $this->assertDatabaseHas('locations', [
            'id' => $area->id,
            'name' => 'Original Area',
            'type' => 'area',
            'parent_id' => $region->id,
            'path' => $areaPath,
            'address' => 'Original area address',
        ]);
        $this->assertDatabaseHas('locations', [
            'id' => $pop->id,
            'name' => 'Original POP',
            'type' => 'pop',
            'parent_id' => $area->id,
            'path' => $popPath,
            'address' => 'Original POP address',
        ]);
    }

    public function test_service_update_rejects_blank_parent_when_existing_parent_is_deleted(): void
    {
        CompanyService::useCompanyForTests($this->company->id);

        $parent = $this->location(['code' => 'DELETED-PARENT', 'type' => 'region']);
        $child = $this->location([
            'code' => 'DELETED-CHILD',
            'type' => 'area',
            'parent_id' => $parent->id,
        ]);
        $parent->delete();

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('Induk lokasi tidak ditemukan atau tidak valid.');

        try {
            LocationService::update($child, [
                'name' => 'Must not persist',
                'type' => 'area',
                'parent_id' => '',
            ]);
        } finally {
            $this->assertDatabaseHas('locations', [
                'id' => $child->id,
                'name' => $child->name,
                'parent_id' => $parent->id,
                'path' => $child->path,
            ]);
        }
    }

    public function test_service_create_enforces_company_context_and_overwrites_payload_company(): void
    {
        CompanyService::useCompanyForTests(null);
        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('Company context is required.');

        LocationService::create([
            'code' => 'NO-COMP-1',
            'name' => 'No Company',
            'type' => 'region',
        ]);
    }

    public function test_service_create_sets_trusted_company_and_ignores_path_input(): void
    {
        CompanyService::useCompanyForTests($this->company->id);

        $created = LocationService::create([
            'company_id' => $this->otherCompany->id,
            'path' => 'FORGED > PATH',
            'code' => 'SERV-CREATE-1',
            'name' => 'Service Create',
            'type' => 'region',
        ]);

        $this->assertSame($this->company->id, $created->company_id);
        $this->assertSame('SERV-CREATE-1', $created->path);
    }

    public function test_service_create_builds_full_location_hierarchy_and_chains_paths(): void
    {
        CompanyService::useCompanyForTests($this->company->id);

        $region = LocationService::create([
            'code' => 'HIER-REGION',
            'name' => 'Hierarchy Region',
            'type' => 'region',
        ]);
        $area = LocationService::create([
            'code' => 'HIER-AREA',
            'name' => 'Hierarchy Area',
            'type' => 'area',
            'parent_id' => $region->id,
        ]);
        $pop = LocationService::create([
            'code' => 'HIER-POP',
            'name' => 'Hierarchy POP',
            'type' => 'pop',
            'parent_id' => $area->id,
        ]);
        $rack = LocationService::create([
            'code' => 'HIER-RACK',
            'name' => 'Hierarchy Rack',
            'type' => 'rack',
            'parent_id' => $pop->id,
        ]);
        $site = LocationService::create([
            'code' => 'HIER-SITE',
            'name' => 'Hierarchy Site',
            'type' => 'site',
            'parent_id' => $rack->id,
        ]);

        $this->assertSame('HIER-REGION', $region->path);
        $this->assertSame('HIER-REGION > HIER-AREA', $area->path);
        $this->assertSame('HIER-REGION > HIER-AREA > HIER-POP', $pop->path);
        $this->assertSame('HIER-REGION > HIER-AREA > HIER-POP > HIER-RACK', $rack->path);
        $this->assertSame('HIER-REGION > HIER-AREA > HIER-POP > HIER-RACK > HIER-SITE', $site->path);

        $this->assertSame($region->id, $area->parent_id);
        $this->assertSame($area->id, $pop->parent_id);
        $this->assertSame($pop->id, $rack->parent_id);
        $this->assertSame($rack->id, $site->parent_id);
    }

    public function test_service_cross_level_parent_is_rejected_for_create_update_and_move_without_mutation(): void
    {
        CompanyService::useCompanyForTests($this->company->id);

        $region = $this->location(['code' => 'CROSS-REGION', 'type' => 'region']);
        $pop = $this->location([
            'code' => 'CROSS-POP',
            'type' => 'pop',
            'parent_id' => $this->location(['code' => 'CROSS-AREA', 'type' => 'area', 'parent_id' => $region->id])->id,
        ]);
        $area = $this->location([
            'code' => 'CROSS-AREA-CHILD',
            'type' => 'area',
            'parent_id' => $region->id,
            'name' => 'Original area',
        ]);

        try {
            LocationService::create([
                'code' => 'CROSS-CREATE',
                'name' => 'Invalid cross-level create',
                'type' => 'area',
                'parent_id' => $pop->id,
            ]);
            $this->fail('Expected cross-level create validation failure.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('parent_id', $e->errors());
        }

        try {
            LocationService::update($area, [
                'name' => 'Must not persist',
                'type' => 'area',
                'parent_id' => $pop->id,
            ]);
            $this->fail('Expected cross-level update validation failure.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('parent_id', $e->errors());
        }

        try {
            LocationService::move($area, $pop->id);
            $this->fail('Expected cross-level move validation failure.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('new_parent_id', $e->errors());
        }

        $this->assertDatabaseMissing('locations', ['code' => 'CROSS-CREATE']);
        $this->assertDatabaseHas('locations', [
            'id' => $area->id,
            'name' => 'Original area',
            'parent_id' => $region->id,
            'path' => $area->path,
        ]);
    }

    public function test_service_create_rejects_region_with_nonblank_parent_without_writing(): void
    {
        CompanyService::useCompanyForTests($this->company->id);

        $parent = $this->location(['code' => 'REGION-PARENT', 'type' => 'region']);

        try {
            LocationService::create([
                'code' => 'REGION-WITH-PARENT',
                'name' => 'Invalid Region',
                'type' => 'region',
                'parent_id' => $parent->id,
            ]);
            $this->fail('Expected validation failure for a region with a parent.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('parent_id', $e->errors());
        }

        $this->assertDatabaseMissing('locations', ['code' => 'REGION-WITH-PARENT']);
    }

    public function test_service_create_stores_region_with_blank_or_missing_parent_at_root(): void
    {
        CompanyService::useCompanyForTests($this->company->id);

        $blank = LocationService::create([
            'code' => 'REGION-BLANK-PARENT',
            'name' => 'Blank Parent Region',
            'type' => 'region',
            'parent_id' => '',
        ]);
        $missing = LocationService::create([
            'code' => 'REGION-MISSING-PARENT',
            'name' => 'Missing Parent Region',
            'type' => 'region',
        ]);

        $this->assertNull($blank->parent_id);
        $this->assertSame('REGION-BLANK-PARENT', $blank->path);
        $this->assertNull($missing->parent_id);
        $this->assertSame('REGION-MISSING-PARENT', $missing->path);
        $this->assertDatabaseHas('locations', [
            'id' => $blank->id,
            'parent_id' => null,
            'path' => 'REGION-BLANK-PARENT',
        ]);
        $this->assertDatabaseHas('locations', [
            'id' => $missing->id,
            'parent_id' => null,
            'path' => 'REGION-MISSING-PARENT',
        ]);
    }

    public function test_service_create_rejects_non_region_with_blank_or_missing_parent_without_writing(): void
    {
        CompanyService::useCompanyForTests($this->company->id);

        foreach ([
            ['code' => 'NONREGION-BLANK-PARENT', 'parent_id' => ''],
            ['code' => 'NONREGION-MISSING-PARENT'],
        ] as $payload) {
            try {
                LocationService::create(array_merge([
                    'name' => 'Invalid Non-Region',
                    'type' => 'area',
                ], $payload));
                $this->fail('Expected validation failure for a non-region without a parent.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('parent_id', $e->errors());
            }
        }

        $this->assertDatabaseMissing('locations', ['code' => 'NONREGION-BLANK-PARENT']);
        $this->assertDatabaseMissing('locations', ['code' => 'NONREGION-MISSING-PARENT']);
    }

    public function test_service_rejects_foreign_or_deleted_parent(): void
    {
        CompanyService::useCompanyForTests($this->company->id);

        $foreignParent = $this->location([
            'code' => 'FOR-PAR',
            'type' => 'region',
            'company_id' => $this->otherCompany->id,
        ]);

        $deletedParent = $this->location([
            'code' => 'DEL-PAR',
            'type' => 'region',
            'company_id' => $this->company->id,
        ]);
        $deletedParent->delete();

        try {
            LocationService::create([
                'code' => 'SERV-CHILD-1',
                'name' => 'Child 1',
                'type' => 'area',
                'parent_id' => $foreignParent->id,
            ]);
            $this->fail('Expected HttpException 404 for foreign parent.');
        } catch (HttpException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }

        try {
            LocationService::create([
                'code' => 'SERV-CHILD-2',
                'name' => 'Child 2',
                'type' => 'area',
                'parent_id' => $deletedParent->id,
            ]);
            $this->fail('Expected HttpException 404 for deleted parent.');
        } catch (HttpException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
    }

    public function test_service_update_and_move_reject_foreign_location_or_foreign_parent(): void
    {
        CompanyService::useCompanyForTests($this->company->id);

        $ownLoc = $this->location(['code' => 'OWN-L', 'type' => 'area']);
        $foreignLoc = $this->location(['code' => 'FOR-L', 'type' => 'area', 'company_id' => $this->otherCompany->id]);
        $foreignParent = $this->location(['code' => 'FOR-P', 'type' => 'region', 'company_id' => $this->otherCompany->id]);

        // Updating foreign location via service throws 404
        try {
            LocationService::update($foreignLoc, ['name' => 'Attempt']);
            $this->fail('Expected 404 on updating foreign location.');
        } catch (HttpException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }

        // Moving to foreign parent throws 404
        try {
            LocationService::move($ownLoc, $foreignParent->id);
            $this->fail('Expected 404 on moving to foreign parent.');
        } catch (HttpException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
    }

    public function test_index_handles_malformed_array_query_gracefully(): void
    {
        $this->location(['code' => 'SAFE-1', 'name' => 'Jakarta Safe', 'type' => 'pop']);

        $this->actingAs($this->admin)
            ->get(route('admin.locations.index', [
                'search' => ['unexpected' => 'array'],
                'type' => ['unexpected' => 'array'],
                'status' => ['unexpected' => 'array'],
                'sort' => ['unexpected' => 'array'],
                'direction' => ['unexpected' => 'array'],
                'per_page' => ['unexpected' => 'array'],
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Locations/Index')
                ->where('filters.search', null)
                ->where('filters.type', null)
                ->where('filters.status', null)
                ->where('filters.sort', null)
                ->where('filters.direction', null)
                ->where('filters.per_page', null)
            );
    }

    public function test_the_table_rows_carry_no_children_so_the_page_reads_them_from_the_tree(): void
    {
        // The Tabel view of the page used `row.children.length` on these rows and crashed for anyone who may delete.
        $region = $this->location(['code' => 'REG-1', 'type' => 'region']);
        $area = $this->location(['code' => 'AREA-1', 'type' => 'area', 'parent_id' => $region->id, 'path' => 'REG-1 > AREA-1']);

        $this->actingAs($this->admin)
            ->get(route('admin.locations.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('locations.data', 2)
                ->missing('locations.data.0.children')
                ->missing('locations.data.1.children')
                ->where('locations.data.1.parent_id', $region->public_id)
                ->where('tree.data.0.id', $region->public_id)
                ->where('tree.data.0.children.0.id', $area->public_id)
                ->where('tree.data.0.children.0.children', [])
            );
    }

    public function test_store_answers_in_indonesian(): void
    {
        $this->actingAs($this->admin);
        $region = $this->location(['code' => 'REG-1', 'type' => 'region']);

        $this->postJson(route('admin.locations.store'), ['code' => 'REG-2', 'name' => 'Wilayah', 'type' => 'region', 'parent_id' => $region->public_id])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['parent_id' => 'Lokasi Wilayah tidak boleh punya induk.']);
        $this->postJson(route('admin.locations.store'), ['code' => 'AREA-2', 'name' => 'Area', 'type' => 'area'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['parent_id' => 'Induk lokasi wajib diisi untuk lokasi selain Wilayah.']);
        $this->postJson(route('admin.locations.store'), ['code' => 'REG-3', 'name' => 'Wilayah', 'type' => 'region', 'lat' => '-6.2'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['lat' => 'Lintang dan bujur harus diisi bersamaan.']);
        $this->postJson(route('admin.locations.store'), ['code' => 'A > B', 'name' => 'Wilayah', 'type' => 'region'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['code' => 'Kode tidak boleh memuat tanda ">".']);
        $this->postJson(route('admin.locations.store'), ['type' => 'region'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['code' => 'kode wajib diisi.', 'name' => 'nama wajib diisi.']);

        $this->post(route('admin.locations.store'), ['code' => 'REG-4', 'name' => 'Wilayah', 'type' => 'region'])
            ->assertSessionHas('success', 'Lokasi dibuat.');
    }

    public function test_service_refusals_are_indonesian(): void
    {
        $this->actingAs($this->admin);
        $region = $this->location(['code' => 'REG-1', 'type' => 'region']);
        $area = $this->location(['code' => 'AREA-1', 'type' => 'area', 'parent_id' => $region->id, 'path' => 'REG-1 > AREA-1']);
        $pop = $this->location(['code' => 'POP-1', 'type' => 'pop', 'parent_id' => $area->id, 'path' => 'REG-1 > AREA-1 > POP-1']);

        $this->deleteJson(route('admin.locations.destroy', $region))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['location' => 'Lokasi masih punya lokasi turunan.']);
        $this->postJson(route('admin.locations.move', $pop), ['new_parent_id' => $region->public_id])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['new_parent_id' => 'Lokasi POP harus punya induk Area.']);
        $this->postJson(route('admin.locations.move', $pop), [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['new_parent_id' => 'induk baru wajib diisi.']);
        $this->putJson(route('admin.locations.update', $area), ['code' => 'AREA-1', 'name' => 'Area', 'type' => 'rack'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['type' => 'Lokasi POP tidak dapat punya induk Rak.']);

        $this->delete(route('admin.locations.destroy', $pop))->assertSessionHas('success', 'Lokasi dihapus.');
        $this->post(route('admin.locations.move', $area), ['new_parent_id' => $region->public_id])->assertSessionHas('success', 'Lokasi dipindahkan.');
        $this->put(route('admin.locations.update', $area), ['code' => 'AREA-1', 'name' => 'Area baru', 'type' => 'area'])->assertSessionHas('success', 'Lokasi diperbarui.');
    }
}
