<?php

namespace Tests\Feature;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Core\Company;
use App\Models\Core\EmployeeProfile;
use App\Models\Core\OrganizationUnit;
use App\Models\User;
use App\Support\OptimisticLock;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SystemSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;
use Tests\Traits\AllowsManualCodes;

class EmployeeTest extends TestCase
{
    use AllowsManualCodes;
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(SystemSettingSeeder::class);
        app()['cache']->forget('spatie.permission.cache');

        $company = Company::factory()->create(['is_active' => true]);
        $this->admin = User::factory()->create([
            'company_id' => $company->id,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
        $this->grantRole($this->admin, 'admin');
    }

    public function test_create_without_a_number_gets_the_configured_format(): void
    {
        $user = User::factory()->create(['company_id' => $this->admin->company_id, 'is_active' => true]);
        $org = OrganizationUnit::factory()->create(['company_id' => $this->admin->company_id]);

        $this->actingAs($this->admin)->post(route('admin.employees.store'), [
            'user_id' => $user->public_id,
            'organization_id' => $org->public_id,
            'phone' => '08123456789',
            'status' => 'active',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('employee_profiles', ['employee_number' => 'EMP-0001', 'user_id' => $user->id]);
    }

    public function test_create_with_a_number_is_rejected_while_manual_input_is_off(): void
    {
        $user = User::factory()->create(['company_id' => $this->admin->company_id, 'is_active' => true]);

        $this->actingAs($this->admin)->post(route('admin.employees.store'), [
            'user_id' => $user->public_id,
            'employee_number' => 'EMP-TYPED',
            'status' => 'active',
        ])->assertSessionHasErrors(['employee_number']);

        $this->assertDatabaseMissing('employee_profiles', ['user_id' => $user->id]);
    }

    public function test_create_keeps_a_typed_number_when_manual_input_is_on(): void
    {
        $this->setManualCodes($this->admin->company_id, true, 'employee');
        $user = User::factory()->create(['company_id' => $this->admin->company_id, 'is_active' => true]);

        $this->actingAs($this->admin)->post(route('admin.employees.store'), [
            'user_id' => $user->public_id,
            'employee_number' => 'EMP-TYPED',
            'status' => 'active',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('employee_profiles', ['employee_number' => 'EMP-TYPED', 'user_id' => $user->id]);
    }

    public function test_update_ignores_the_number_while_manual_input_is_off(): void
    {
        $profile = EmployeeProfile::factory()->create([
            'company_id' => $this->admin->company_id,
            'user_id' => User::factory()->create(['company_id' => $this->admin->company_id, 'is_active' => true])->id,
            'employee_number' => 'EMP-KEEP',
        ]);

        $this->actingAs($this->admin)->put(route('admin.employees.update', $profile), [
            OptimisticLock::FIELD => $this->version($profile),
            'employee_number' => 'EMP-CHANGED',
            'phone' => '08111111111',
            'status' => 'active',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $profile->refresh();
        $this->assertSame('EMP-KEEP', $profile->employee_number);
        $this->assertSame('08111111111', $profile->phone);
    }

    public function test_update_changes_the_number_when_manual_input_is_on(): void
    {
        $this->setManualCodes($this->admin->company_id, true, 'employee');
        $profile = EmployeeProfile::factory()->create([
            'company_id' => $this->admin->company_id,
            'user_id' => User::factory()->create(['company_id' => $this->admin->company_id, 'is_active' => true])->id,
            'employee_number' => 'EMP-OLD',
        ]);

        $this->actingAs($this->admin)->put(route('admin.employees.update', $profile), [
            OptimisticLock::FIELD => $this->version($profile),
            'employee_number' => 'EMP-NEW',
            'status' => 'active',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('EMP-NEW', $profile->fresh()->employee_number);
    }

    public function test_employee_number_unique_per_company(): void
    {
        $this->setManualCodes($this->admin->company_id, true, 'employee');
        $user1 = User::factory()->create(['company_id' => $this->admin->company_id, 'is_active' => true]);
        EmployeeProfile::factory()->create([
            'company_id' => $this->admin->company_id,
            'user_id' => $user1->id,
            'employee_number' => 'EMP-DUP',
        ]);

        $user2 = User::factory()->create(['company_id' => $this->admin->company_id, 'is_active' => true]);
        $response = $this->actingAs($this->admin)->post(route('admin.employees.store'), [
            'user_id' => $user2->public_id,
            'employee_number' => 'EMP-DUP',
            'status' => 'active',
        ]);

        $response->assertSessionHasErrors(['employee_number']);
    }

    public function test_index_exposes_the_code_sequence(): void
    {
        $this->actingAs($this->admin)->get(route('admin.employees.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('codeSequence.manual', false)
                ->where('codeSequence.preview', 'EMP-0001'));
    }

    public function test_index_returns_200(): void
    {
        $response = $this->actingAs($this->admin)->get(route('admin.employees.index'));
        $response->assertOk();
    }

    public function test_index_gives_the_hire_date_as_a_plain_date_so_the_edit_form_can_preload_it(): void
    {
        EmployeeProfile::factory()->create([
            'company_id' => $this->admin->company_id,
            'user_id' => User::factory()->create(['company_id' => $this->admin->company_id])->id,
            'hire_date' => '2026-03-05',
        ]);

        $this->actingAs($this->admin)->get(route('admin.employees.index'))
            ->assertInertia(fn (Assert $page) => $page->where('employees.data.0.hire_date', '2026-03-05'));
    }

    public function test_the_edit_dialog_saves_every_field_it_preloaded_without_sending_the_user(): void
    {
        $org = OrganizationUnit::factory()->create(['company_id' => $this->admin->company_id]);
        $profile = EmployeeProfile::factory()->create([
            'company_id' => $this->admin->company_id,
            'user_id' => User::factory()->create(['company_id' => $this->admin->company_id, 'is_active' => true])->id,
            'status' => 'active',
        ]);

        $this->actingAs($this->admin)->put(route('admin.employees.update', $profile), [
            OptimisticLock::FIELD => $this->version($profile),
            'organization_id' => $org->public_id,
            'vehicle_id' => null,
            'phone' => '0812000111',
            'hire_date' => '2026-03-05',
            'status' => 'inactive',
            'notes' => 'Pindah shift malam.',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $profile->refresh();
        $this->assertSame($org->id, $profile->organization_id);
        $this->assertSame('2026-03-05', $profile->hire_date->toDateString());
        $this->assertSame('inactive', $profile->status);
        $this->assertSame('Pindah shift malam.', $profile->notes);
    }

    public function test_an_empty_user_in_the_update_payload_is_refused(): void
    {
        $profile = EmployeeProfile::factory()->create([
            'company_id' => $this->admin->company_id,
            'user_id' => User::factory()->create(['company_id' => $this->admin->company_id, 'is_active' => true])->id,
        ]);

        $this->actingAs($this->admin)->put(route('admin.employees.update', $profile), [
            OptimisticLock::FIELD => $this->version($profile),
            'user_id' => '',
            'status' => 'active',
        ])->assertSessionHasErrors(['user_id' => 'pengguna wajib diisi.']);
    }

    public function test_hire_date_must_be_a_plain_date(): void
    {
        $user = User::factory()->create(['company_id' => $this->admin->company_id, 'is_active' => true]);

        $this->actingAs($this->admin)->post(route('admin.employees.store'), [
            'user_id' => $user->public_id,
            'hire_date' => '05/03/2026',
        ])->assertSessionHasErrors(['hire_date' => 'tanggal masuk harus sesuai format Y-m-d.']);

        $this->assertDatabaseMissing('employee_profiles', ['user_id' => $user->id]);
    }

    public function test_a_user_who_already_has_a_profile_is_refused_with_a_message(): void
    {
        $user = User::factory()->create(['company_id' => $this->admin->company_id, 'is_active' => true]);
        EmployeeProfile::factory()->create(['company_id' => $this->admin->company_id, 'user_id' => $user->id]);

        $this->actingAs($this->admin)->post(route('admin.employees.store'), [
            'user_id' => $user->public_id,
        ])->assertSessionHasErrors(['user_id' => 'Pengguna ini sudah punya profil karyawan.']);
    }

    public function test_a_user_of_another_company_who_already_has_a_profile_is_only_told_that_they_are_not_found(): void
    {
        $foreign = User::factory()->create([
            'company_id' => Company::factory()->create(['is_active' => true])->id,
            'is_active' => true,
        ]);
        EmployeeProfile::factory()->create(['company_id' => $foreign->company_id, 'user_id' => $foreign->id]);

        $response = $this->actingAs($this->admin)
            ->postJson(route('admin.employees.store'), ['user_id' => $foreign->public_id])
            ->assertUnprocessable();

        $this->assertCount(1, $response->json('errors.user_id'));
        $this->assertStringNotContainsString('sudah punya profil', (string) $response->getContent());
    }

    public function test_update_refuses_a_user_who_already_has_another_profile_instead_of_failing_on_the_database(): void
    {
        $owner = User::factory()->create(['company_id' => $this->admin->company_id, 'is_active' => true]);
        EmployeeProfile::factory()->create(['company_id' => $this->admin->company_id, 'user_id' => $owner->id]);
        $profile = EmployeeProfile::factory()->create([
            'company_id' => $this->admin->company_id,
            'user_id' => User::factory()->create(['company_id' => $this->admin->company_id, 'is_active' => true])->id,
        ]);
        $before = $profile->user_id;

        $this->actingAs($this->admin)->putJson(route('admin.employees.update', $profile), [
            OptimisticLock::FIELD => $this->version($profile),
            'user_id' => $owner->public_id,
            'status' => 'active',
        ])->assertUnprocessable()->assertJsonPath('errors.user_id.0', 'Pengguna ini sudah punya profil karyawan.');

        $this->assertSame($before, $profile->fresh()->user_id);
    }

    public function test_update_accepts_the_user_the_profile_already_has(): void
    {
        $user = User::factory()->create(['company_id' => $this->admin->company_id, 'is_active' => true]);
        $profile = EmployeeProfile::factory()->create(['company_id' => $this->admin->company_id, 'user_id' => $user->id]);

        $this->actingAs($this->admin)->put(route('admin.employees.update', $profile), [
            OptimisticLock::FIELD => $this->version($profile),
            'user_id' => $user->public_id,
            'phone' => '0812999000',
            'status' => 'active',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('0812999000', $profile->fresh()->phone);
    }

    public function test_a_member_of_the_company_homed_elsewhere_can_get_a_profile(): void
    {
        $member = User::factory()->create([
            'company_id' => Company::factory()->create(['is_active' => true])->id,
            'is_active' => true,
        ]);
        DB::table('company_user')->insert([
            'company_id' => $this->admin->company_id,
            'user_id' => $member->id,
            'is_default' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($this->admin)->post(route('admin.employees.store'), [
            'user_id' => $member->public_id,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('employee_profiles', ['user_id' => $member->id, 'company_id' => $this->admin->company_id]);
    }

    public function test_user_options_load_only_on_request_and_stay_in_the_company(): void
    {
        $mine = User::factory()->create(['name' => 'Qwerty Teknisi', 'company_id' => $this->admin->company_id, 'is_active' => true]);
        User::factory()->create(['name' => 'Qwerty Nonaktif', 'company_id' => $this->admin->company_id, 'is_active' => false]);
        User::factory()->create(['name' => 'Qwerty Asing', 'company_id' => Company::factory()->create(['is_active' => true])->id, 'is_active' => true]);
        $taken = User::factory()->create(['name' => 'Qwerty Terdaftar', 'company_id' => $this->admin->company_id, 'is_active' => true]);
        EmployeeProfile::factory()->create(['company_id' => $this->admin->company_id, 'user_id' => $taken->id]);

        $this->actingAs($this->admin)->get(route('admin.employees.index'))
            ->assertInertia(fn (Assert $page) => $page->missing('userOptions'));

        $this->actingAs($this->admin)
            ->withHeaders([
                'X-Inertia' => 'true',
                'X-Inertia-Version' => app(HandleInertiaRequests::class)->version(request()),
                'X-Inertia-Partial-Component' => 'Admin/Employees/Index',
                'X-Inertia-Partial-Data' => 'userOptions',
            ])
            ->get(route('admin.employees.index', ['user_search' => 'qwerty']))
            ->assertOk()
            ->assertJsonCount(1, 'props.userOptions')
            ->assertJsonPath('props.userOptions.0.value', $mine->public_id)
            ->assertJsonPath('props.userOptions.0.label', 'Qwerty Teknisi');
    }

    /** The version the edit dialog was rendered from. */
    private function version(EmployeeProfile $profile): string
    {
        return OptimisticLock::version($profile->fresh());
    }
}
