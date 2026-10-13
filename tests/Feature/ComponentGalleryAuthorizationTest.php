<?php

namespace Tests\Feature;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Core\Company;
use App\Models\Core\Location;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ComponentGalleryAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_component_gallery_requires_developer_components_view_permission(): void
    {
        $this->actingAs($this->verifiedUser())
            ->get('/admin/components')
            ->assertForbidden();
    }

    public function test_component_gallery_allows_developer_components_view_permission_when_app_is_local(): void
    {
        $user = $this->verifiedUser();
        $user->givePermissionTo('developer.components.view');

        $this->app['env'] = 'local';

        $this->actingAs($user)
            ->get('/admin/components')
            ->assertOk();
    }

    public function test_component_gallery_allows_developer_components_view_permission_when_app_is_testing(): void
    {
        $user = $this->verifiedUser();
        $user->givePermissionTo('developer.components.view');

        $this->actingAs($user)
            ->get('/admin/components')
            ->assertOk();
    }

    public function test_component_gallery_does_not_grant_system_setting_access_to_gallery(): void
    {
        $user = $this->verifiedUser();
        $user->givePermissionTo('system.setting');

        $this->app['env'] = 'local';

        $this->actingAs($user)
            ->get('/admin/components')
            ->assertForbidden();
    }

    public function test_component_gallery_is_not_accessible_when_app_env_is_production_even_with_permission(): void
    {
        $user = $this->verifiedUser();
        $user->givePermissionTo('developer.components.view');

        $this->app['env'] = 'production';

        $this->actingAs($user)
            ->get('/admin/components')
            ->assertNotFound();
    }

    public function test_component_gallery_is_open_in_the_e2e_environment_and_closed_in_staging(): void
    {
        $user = $this->verifiedUser();
        $user->givePermissionTo('developer.components.view');

        $this->app['env'] = 'e2e';
        $this->actingAs($user)->get('/admin/components')->assertOk();

        $this->app['env'] = 'staging';
        $this->actingAs($user)->get('/admin/components')->assertNotFound();
    }

    public function test_demo_options_load_only_on_request_and_stay_in_the_company(): void
    {
        $user = $this->verifiedUser();
        $user->givePermissionTo('developer.components.view');
        $mine = Location::factory()->create(['company_id' => $user->company_id, 'name' => 'Gudang Pusat']);
        Location::factory()->create(['company_id' => Company::factory()->create(['is_active' => true])->id, 'name' => 'Gudang Asing']);

        $this->actingAs($user)
            ->get('/admin/components')
            ->assertInertia(fn (Assert $page) => $page->component('Admin/Components')->missing('demoOptions'));

        $this->actingAs($user)
            ->withHeaders([
                'X-Inertia' => 'true',
                'X-Inertia-Version' => app(HandleInertiaRequests::class)->version(request()),
                'X-Inertia-Partial-Component' => 'Admin/Components',
                'X-Inertia-Partial-Data' => 'demoOptions',
            ])
            ->get('/admin/components?demo_search=gudang')
            ->assertOk()
            ->assertJsonPath('props.demoOptions.0.value', $mine->id)
            ->assertJsonCount(1, 'props.demoOptions');
    }

    private function verifiedUser(): User
    {
        return User::factory()->create([
            'company_id' => Company::factory()->create(['is_active' => true])->id,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
    }
}
