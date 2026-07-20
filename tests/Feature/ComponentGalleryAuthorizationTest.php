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

    public function test_component_gallery_requires_system_setting_permission(): void
    {
        $this->actingAs($this->verifiedUser())
            ->get('/admin/components')
            ->assertForbidden();
    }

    public function test_component_gallery_allows_system_setting_permission_when_app_is_local(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $user = $this->verifiedUser();
        $user->givePermissionTo('system.setting');

        $this->app['env'] = 'local';

        $this->actingAs($user)
            ->get('/admin/components')
            ->assertOk();
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
