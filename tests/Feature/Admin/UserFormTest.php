<?php

namespace Tests\Feature\Admin;

use App\Models\Core\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class UserFormTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create(['is_active' => true]);
        $this->admin = $this->grantRole($this->newUser(), 'admin', $this->company);
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

    public function test_a_user_is_created_with_the_roles_of_this_company(): void
    {
        $roles = $this->companyRoles($this->company);

        $this->actingAs($this->admin)->post(route('admin.users.store'), [
            'name' => 'Dewi Teknisi',
            'email' => 'dewi.teknisi@example.test',
            'password' => 'rahasia-1234',
            'roles' => [$roles['technician']->name],
        ])->assertRedirect(route('admin.users.index'))->assertSessionHasNoErrors();

        $created = User::query()->where('email', 'dewi.teknisi@example.test')->firstOrFail();
        $this->assertSame($this->company->id, $created->company_id, 'he is created for the company the administrator works in');
        $this->assertSame(
            [$roles['technician']->id],
            DB::table('model_has_roles')->where('model_id', $created->id)->pluck('role_id')->map(fn ($id): int => (int) $id)->all(),
        );
    }

    public function test_a_role_of_another_company_is_not_a_role_here(): void
    {
        $other = Company::factory()->create(['is_active' => true]);
        $foreign = $this->companyRole($other, 'Peran Perusahaan Lain', ['ticket.view']);

        $this->actingAs($this->admin)->post(route('admin.users.store'), [
            'name' => 'Calon Asing',
            'email' => 'calon.asing@example.test',
            'password' => 'rahasia-1234',
            'roles' => [$foreign->name],
        ])->assertSessionHasErrors('roles.0');

        $this->assertFalse(User::query()->where('email', 'calon.asing@example.test')->exists());
    }

    public function test_a_role_richer_than_the_actor_is_refused_and_the_refusal_is_in_indonesian(): void
    {
        $managing = $this->companyRole($this->company, 'Pengelola Peran', ['roles.manage']);
        $manager = $this->grantRole($this->newUser(), 'manager', $this->company);
        $manager->givePermissionTo('users.manage');

        $this->actingAs($manager)->post(route('admin.users.store'), [
            'name' => 'Calon Admin',
            'email' => 'calon.admin@example.test',
            'password' => 'rahasia-1234',
            'roles' => [$managing->name],
        ])->assertSessionHasErrors(['roles' => 'Anda tidak dapat memberi hak akses yang tidak Anda miliki: roles.manage.']);

        $this->assertFalse(User::query()->where('email', 'calon.admin@example.test')->exists());
    }

    private function newUser(): User
    {
        return User::factory()->create([
            'company_id' => $this->company->id,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
    }
}
