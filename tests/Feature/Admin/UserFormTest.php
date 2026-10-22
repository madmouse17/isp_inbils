<?php

namespace Tests\Feature\Admin;

use App\Models\Core\Company;
use App\Models\Core\EmployeeProfile;
use App\Models\User;
use App\Notifications\StaffAccountInvitation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;
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
        Notification::fake();
    }

    public function test_a_user_can_be_created_inactive(): void
    {
        $this->actingAs($this->admin)->post(route('admin.users.store'), [
            'name' => 'Budi Cuti',
            'email' => 'budi.cuti@example.test',
            'is_active' => false,
            'roles' => [],
        ])->assertRedirect(route('admin.users.index'))->assertSessionHas('success', 'Pengguna dibuat. Undangan dikirim ke budi.cuti@example.test.');

        $created = User::query()->where('email', 'budi.cuti@example.test')->firstOrFail();
        $this->assertFalse($created->is_active);
        Notification::assertSentTo($created, StaffAccountInvitation::class);
    }

    public function test_a_user_is_created_active_when_the_form_says_nothing(): void
    {
        $this->actingAs($this->admin)->post(route('admin.users.store'), [
            'name' => 'Sari Aktif',
            'email' => 'sari.aktif@example.test',
        ])->assertRedirect(route('admin.users.index'));

        $this->assertTrue(User::query()->where('email', 'sari.aktif@example.test')->firstOrFail()->is_active);
    }

    public function test_a_user_is_created_with_the_roles_of_this_company(): void
    {
        $roles = $this->companyRoles($this->company);

        $this->actingAs($this->admin)->post(route('admin.users.store'), [
            'name' => 'Dewi Teknisi',
            'email' => 'dewi.teknisi@example.test',
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
            'roles' => [$managing->name],
        ])->assertSessionHasErrors(['roles' => 'Anda tidak dapat memberi hak akses yang tidak Anda miliki: roles.manage.']);

        $this->assertFalse(User::query()->where('email', 'calon.admin@example.test')->exists());
    }

    public function test_a_user_can_be_created_with_an_employee_profile(): void
    {
        $this->actingAs($this->admin)->post(route('admin.users.store'), [
            'name' => 'Rina Petugas',
            'email' => 'rina.petugas@example.test',
            'employee' => [
                'phone' => '081234567890',
                'hire_date' => '2026-10-20',
                'status' => 'active',
                'notes' => 'Petugas lapangan.',
            ],
        ])->assertRedirect(route('admin.users.index'))->assertSessionHasNoErrors();

        $user = User::query()->where('email', 'rina.petugas@example.test')->firstOrFail();
        $profile = EmployeeProfile::query()->where('user_id', $user->id)->firstOrFail();
        $this->assertSame('081234567890', $profile->phone);
        $this->assertSame('2026-10-20', $profile->hire_date?->toDateString());
        $this->assertSame('active', $profile->status);
        $this->assertStringStartsWith('EMP-', $profile->employee_number);
    }

    public function test_an_employee_profile_needs_the_employee_permission_not_only_users_manage(): void
    {
        $itAdmin = $this->grantRole($this->newUser(), $this->companyRole($this->company, 'Admin TI', ['app.web', 'users.manage']), $this->company);

        $this->actingAs($itAdmin)->get(route('admin.users.create'))
            ->assertInertia(fn (Assert $page) => $page->where('can.createEmployee', false));
        $this->actingAs($this->admin)->get(route('admin.users.create'))
            ->assertInertia(fn (Assert $page) => $page->where('can.createEmployee', true));

        $this->actingAs($itAdmin)->post(route('admin.users.store'), [
            'name' => 'Tanpa HR',
            'email' => 'tanpa.hr@example.test',
            'employee' => ['phone' => '081200000000', 'status' => 'active'],
        ])->assertForbidden();
        $this->assertFalse(User::query()->where('email', 'tanpa.hr@example.test')->exists());
        $this->assertSame(0, EmployeeProfile::query()->count());

        $this->actingAs($itAdmin)->post(route('admin.users.store'), [
            'name' => 'Tanpa Profil',
            'email' => 'tanpa.profil@example.test',
            'employee' => null,
        ])->assertRedirect(route('admin.users.index'));
        $this->assertSame(0, EmployeeProfile::query()->count());
    }

    public function test_sending_and_resending_an_invitation_leave_an_audit_row(): void
    {
        $this->actingAs($this->admin)->post(route('admin.users.store'), ['name' => 'Dian Audit', 'email' => 'dian.audit@example.test'])->assertRedirect();
        $created = User::query()->where('email', 'dian.audit@example.test')->firstOrFail();

        $sent = Activity::query()->where('log_name', 'security')->where('description', 'user.invitation_sent')->sole();
        $this->assertSame([User::class, $created->id, $this->admin->id], [$sent->subject_type, (int) $sent->subject_id, (int) $sent->causer_id]);
        $this->assertEquals(['user_id' => $created->id], $sent->properties->all());

        $this->actingAs($this->admin)->post(route('admin.users.invitation.resend', $created))->assertRedirect();

        $resent = Activity::query()->where('log_name', 'security')->where('description', 'user.invitation_resent')->sole();
        $this->assertSame([User::class, $created->id, $this->admin->id], [$resent->subject_type, (int) $resent->subject_id, (int) $resent->causer_id]);
        $this->assertEquals(['user_id' => $created->id], $resent->properties->all());
    }

    public function test_the_throttles_of_the_nas_sync_and_the_audience_count_have_their_own_bucket(): void
    {
        foreach (['admin.nas-devices.sync' => 'throttle:6,1,nas-sync', 'admin.announcements.audience' => 'throttle:60,1,announcement-audience'] as $name => $throttle) {
            $this->assertContains($throttle, Route::getRoutes()->getByName($name)->gatherMiddleware(), $name);
        }
    }

    public function test_created_user_ignores_a_typed_password_and_queues_the_invitation(): void
    {
        $typed = 'admin-must-not-choose-this';

        $this->actingAs($this->admin)->post(route('admin.users.store'), [
            'name' => 'Sinta Undangan',
            'email' => 'sinta.undangan@example.test',
            'password' => $typed,
        ])->assertRedirect(route('admin.users.index'))->assertSessionHasNoErrors();

        $user = User::query()->where('email', 'sinta.undangan@example.test')->firstOrFail();
        $this->assertFalse(Hash::check($typed, $user->password));
        Notification::assertSentTo($user, fn (StaffAccountInvitation $notification): bool => $notification->companyId === $this->company->id);
    }

    public function test_invitation_can_be_resent_only_to_an_active_user_who_has_not_logged_in(): void
    {
        $invitee = $this->newUser();

        $this->actingAs($this->admin)->post(route('admin.users.invitation.resend', $invitee))
            ->assertRedirect()
            ->assertSessionHas('success', 'Undangan dikirim ulang ke '.$invitee->email.'.');
        Notification::assertSentTo($invitee, StaffAccountInvitation::class);

        $invitee->update(['last_login_at' => now()]);
        $this->actingAs($this->admin)->post(route('admin.users.invitation.resend', $invitee))
            ->assertSessionHasErrors('invitation');
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
