<?php

namespace Tests\Feature;

use App\Models\Core\Company;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    private function verifiedUser(): User
    {
        return User::factory()->create([
            'company_id' => Company::factory()->create(['is_active' => true])->id,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
    }
}
