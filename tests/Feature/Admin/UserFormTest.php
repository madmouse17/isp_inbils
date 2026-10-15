<?php

namespace Tests\Feature\Admin;

use App\Models\Core\Company;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserFormTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        app()['cache']->forget('spatie.permission.cache');

        $this->admin = User::factory()->create([
            'company_id' => Company::factory()->create(['is_active' => true])->id,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
        $this->admin->assignRole('admin');
    }

    public function test_a_user_can_be_created_inactive(): void
    {
        $this->actingAs($this->admin)->post(route('admin.users.store'), [
            'name' => 'Budi Cuti',
            'email' => 'budi.cuti@example.test',
            'password' => 'rahasia-1234',
            'is_active' => false,
            'roles' => [],
        ])->assertRedirect(route('admin.users.index'))->assertSessionHas('success', 'Pengguna dibuat.');

        $this->assertFalse(User::query()->where('email', 'budi.cuti@example.test')->firstOrFail()->is_active);
    }

    public function test_a_user_is_created_active_when_the_form_says_nothing(): void
    {
        $this->actingAs($this->admin)->post(route('admin.users.store'), [
            'name' => 'Sari Aktif',
            'email' => 'sari.aktif@example.test',
            'password' => 'rahasia-1234',
        ])->assertRedirect(route('admin.users.index'));

        $this->assertTrue(User::query()->where('email', 'sari.aktif@example.test')->firstOrFail()->is_active);
    }

    public function test_only_an_administrator_may_give_the_admin_role_and_the_refusal_is_in_indonesian(): void
    {
        $manager = User::factory()->create(['company_id' => $this->admin->company_id, 'email_verified_at' => now(), 'is_active' => true]);
        $manager->assignRole('manager');
        $manager->givePermissionTo('users.manage');

        $this->actingAs($manager)->post(route('admin.users.store'), [
            'name' => 'Calon Admin',
            'email' => 'calon.admin@example.test',
            'password' => 'rahasia-1234',
            'roles' => ['admin'],
        ])->assertSessionHasErrors(['roles' => 'Hanya administrator yang boleh memberi peran admin.']);

        $this->assertFalse(User::query()->where('email', 'calon.admin@example.test')->exists());
    }
}
