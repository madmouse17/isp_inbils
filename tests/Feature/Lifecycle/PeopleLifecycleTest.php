<?php

namespace Tests\Feature\Lifecycle;

use App\Http\Middleware\RedirectAppOnlyRoles;
use App\Models\Core\Company;
use App\Models\Core\EmployeeEvaluation;
use App\Models\Core\EmployeeProfile;
use App\Models\Core\OrganizationUnit;
use App\Models\Core\Vehicle;
use App\Models\User;
use App\Services\Core\OrganizationService;
use App\Support\OptimisticLock;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SystemSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Modules\SPK\Database\Factories\WorkOrderFactory;
use Modules\SPK\Models\WorkOrder;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;
use Tests\Traits\AllowsManualCodes;

/**
 * The people records (spec .scratch/master-lifecycle): a vehicle or organization unit that anything points at is
 * deactivated, never deleted, and a hard delete happens only when nothing ever pointed at it. Employees and
 * evaluations keep their soft delete and gain the Dihapus filter and Pulihkan. The same status filter, reference
 * counts and permission rules back the pages.
 */
class PeopleLifecycleTest extends TestCase
{
    use AllowsManualCodes;
    use RefreshDatabase;

    private Company $company;

    private User $admin;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SystemSettingSeeder::class);
        app()['cache']->forget('spatie.permission.cache');

        $this->company = Company::factory()->create(['is_active' => true]);
        $this->admin = $this->user('admin');
    }

    public function test_a_vehicle_an_employee_drives_is_refused_even_when_that_employee_is_deleted_and_can_only_be_deactivated(): void
    {
        $vehicle = $this->vehicle('B 1 AA');
        $driver = $this->employee(['vehicle_id' => $vehicle->id]);
        $this->actingAs($this->admin);

        $this->deleteJson(route('admin.vehicles.destroy', $vehicle))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['lifecycle' => 'Kendaraan masih dipakai 1 karyawan. Nonaktifkan saja.']);

        $driver->delete();
        $this->deleteJson(route('admin.vehicles.destroy', $vehicle))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['lifecycle' => 'Kendaraan masih dipakai 1 karyawan. Nonaktifkan saja.']);
        $this->assertDatabaseHas('vehicles', ['id' => $vehicle->id, 'deleted_at' => null]);
        $this->assertSame($vehicle->id, EmployeeProfile::withTrashed()->sole()->vehicle_id, 'the SET NULL key never cleared the vehicle');

        $this->from('/back')->post(route('admin.vehicles.deactivate', $vehicle->public_id))
            ->assertRedirect('/back')->assertSessionHas('success', 'Kendaraan dinonaktifkan.');
        $this->assertFalse($vehicle->fresh()->is_active);

        $this->from('/back')->post(route('admin.vehicles.activate', $vehicle->public_id))
            ->assertSessionHas('success', 'Kendaraan diaktifkan.');
        $this->assertTrue($vehicle->fresh()->is_active);
    }

    public function test_an_unreferenced_vehicle_is_hard_deleted_and_audited(): void
    {
        $vehicle = $this->vehicle('B 2 BB');

        $this->actingAs($this->admin)->from('/back')->delete(route('admin.vehicles.destroy', $vehicle))
            ->assertRedirect('/back')->assertSessionHas('success', 'Kendaraan dihapus permanen.');

        $this->assertDatabaseMissing('vehicles', ['id' => $vehicle->id]);
        $this->assertTrue(Activity::query()->where('description', 'force_deleted')->where('properties->id', $vehicle->public_id)->exists());
    }

    public function test_the_vehicle_index_filters_by_status_counts_references_and_flags_what_the_user_may_do(): void
    {
        $driven = $this->vehicle('B 1 PAKAI');
        $this->employee(['vehicle_id' => $driven->id]);
        $this->vehicle('B 2 BEBAS');
        $this->vehicle('B 3 NON', ['is_active' => false]);
        $this->vehicle('B 4 HAPUS')->delete();

        $all = ['B 1 PAKAI', 'B 2 BEBAS', 'B 3 NON', 'B 4 HAPUS'];
        $this->assertSame(['B 1 PAKAI', 'B 2 BEBAS', 'B 3 NON'], $this->rows('admin.vehicles.index', 'vehicles', 'plate_number'));
        $this->assertSame(['B 1 PAKAI', 'B 2 BEBAS', 'B 3 NON'], $this->rows('admin.vehicles.index', 'vehicles', 'plate_number', 'bogus'), 'an unknown value is the default view');
        $this->assertSame(['B 1 PAKAI', 'B 2 BEBAS'], $this->rows('admin.vehicles.index', 'vehicles', 'plate_number', 'aktif'));
        $this->assertSame(['B 3 NON'], $this->rows('admin.vehicles.index', 'vehicles', 'plate_number', 'nonaktif'));
        $this->assertSame(['B 4 HAPUS'], $this->rows('admin.vehicles.index', 'vehicles', 'plate_number', 'dihapus'));
        $this->assertSame($all, $this->rows('admin.vehicles.index', 'vehicles', 'plate_number', 'semua'));

        $props = $this->props($this->actingAs($this->admin)->get(route('admin.vehicles.index', ['per_page' => 50])));
        $rows = collect($props['vehicles']['data'])->keyBy('plate_number');
        $this->assertSame(['karyawan' => 1], $this->counted($rows['B 1 PAKAI']));
        $this->assertSame([['label' => 'karyawan', 'count' => 0]], $rows['B 2 BEBAS']['references'], 'every label is listed, the unused ones at zero');
        $this->assertNull($rows['B 2 BEBAS']['deleted_at']);
        $this->assertSame(['update' => true, 'delete' => true], collect($props['can'])->only(['update', 'delete'])->all());

        $deleted = $this->props($this->get(route('admin.vehicles.index', ['status' => 'dihapus'])));
        $this->assertSame('dihapus', $deleted['filters']['status']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d+Z$/', $deleted['vehicles']['data'][0]['deleted_at']);
    }

    public function test_a_unit_with_sub_units_employees_or_completed_spk_is_refused_and_each_reference_is_named(): void
    {
        $branch = $this->unit('CAB');
        $this->unit('AREA', $branch);
        $this->employee(['organization_id' => $branch->id]);
        $this->completedSpk($branch);
        $this->actingAs($this->admin);

        $this->deleteJson(route('admin.organizations.destroy', $branch))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['lifecycle' => 'Unit organisasi masih dipakai 1 sub-unit, 1 karyawan, 1 SPK selesai. Nonaktifkan saja.']);
        $this->assertDatabaseHas('organization_units', ['id' => $branch->id, 'deleted_at' => null]);

        $this->from('/back')->post(route('admin.organizations.deactivate', $branch->public_id))
            ->assertRedirect('/back')->assertSessionHas('success', 'Unit organisasi dinonaktifkan.');
        $this->assertFalse($branch->fresh()->is_active);

        $this->from('/back')->post(route('admin.organizations.activate', $branch->public_id))
            ->assertSessionHas('success', 'Unit organisasi diaktifkan.');
        $this->assertTrue($branch->fresh()->is_active);
    }

    public function test_deleted_sub_units_and_employees_still_count_against_their_unit(): void
    {
        $branch = $this->unit('CAB');
        $this->unit('AREA', $branch)->delete();
        $this->employee(['organization_id' => $branch->id])->delete();

        $this->actingAs($this->admin)->deleteJson(route('admin.organizations.destroy', $branch))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['lifecycle' => 'Unit organisasi masih dipakai 1 sub-unit, 1 karyawan. Nonaktifkan saja.']);
        $this->assertDatabaseHas('organization_units', ['id' => $branch->id, 'deleted_at' => null]);
    }

    public function test_an_unreferenced_unit_is_hard_deleted_and_audited(): void
    {
        $unit = $this->unit('SEMENTARA');

        $this->actingAs($this->admin)->from('/back')->delete(route('admin.organizations.destroy', $unit))
            ->assertRedirect('/back')->assertSessionHas('success', 'Unit organisasi dihapus permanen.');

        $this->assertDatabaseMissing('organization_units', ['id' => $unit->id]);
        $this->assertTrue(Activity::query()->where('description', 'force_deleted')->where('properties->id', $unit->public_id)->exists());
    }

    public function test_the_unit_index_filters_by_status_counts_references_and_flags_what_the_user_may_do(): void
    {
        $parent = $this->unit('PAR');
        $this->unit('KID', $parent);
        $this->unit('OFF', attributes: ['is_active' => false]);
        $this->unit('GONE')->delete();

        $this->assertSame(['KID', 'OFF', 'PAR'], $this->rows('admin.organizations.index', 'organizations', 'code'));
        $this->assertSame(['KID', 'OFF', 'PAR'], $this->rows('admin.organizations.index', 'organizations', 'code', 'bogus'), 'an unknown value is the default view');
        $this->assertSame(['KID', 'PAR'], $this->rows('admin.organizations.index', 'organizations', 'code', 'aktif'));
        $this->assertSame(['OFF'], $this->rows('admin.organizations.index', 'organizations', 'code', 'nonaktif'));
        $this->assertSame(['GONE'], $this->rows('admin.organizations.index', 'organizations', 'code', 'dihapus'));
        $this->assertSame(['GONE', 'KID', 'OFF', 'PAR'], $this->rows('admin.organizations.index', 'organizations', 'code', 'semua'));

        $props = $this->props($this->actingAs($this->admin)->get(route('admin.organizations.index')));
        $rows = collect($props['organizations']['data'])->keyBy('code');
        $this->assertSame(['sub-unit' => 1], $this->counted($rows['PAR']));
        $this->assertSame([
            ['label' => 'sub-unit', 'count' => 0],
            ['label' => 'karyawan', 'count' => 0],
            ['label' => 'SPK selesai', 'count' => 0],
        ], $rows['KID']['references']);
        $this->assertNull($rows['KID']['deleted_at']);
        $this->assertSame(['update' => true, 'delete' => true], collect($props['can'])->only(['update', 'delete'])->all());

        $deleted = $this->props($this->get(route('admin.organizations.index', ['status' => 'dihapus'])));
        $this->assertSame('dihapus', $deleted['filters']['status']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d+Z$/', $deleted['organizations']['data'][0]['deleted_at']);
    }

    public function test_a_child_of_a_deleted_unit_still_names_its_parent_and_comes_back_only_after_it(): void
    {
        $parent = $this->unit('PAR');
        $child = $this->unit('KID', $parent);
        $parent->delete();

        $rows = collect($this->props($this->actingAs($this->admin)->get(route('admin.organizations.index', ['status' => 'semua'])))['organizations']['data'])->keyBy('code');
        $this->assertSame($parent->public_id, $rows['KID']['parent_id'], 'saving the child never drops its deleted parent');

        $child->delete();
        $this->postJson(route('admin.organizations.restore', $child->public_id))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['lifecycle' => 'Unit organisasi tidak bisa dipulihkan karena induknya, Unit PAR, masih dihapus. Pulihkan induknya dulu.']);
        $this->assertSoftDeleted($child);

        $this->from('/back')->post(route('admin.organizations.restore', $parent->public_id))->assertSessionHas('success', 'Unit organisasi dipulihkan.');
        $this->post(route('admin.organizations.restore', $child->public_id))->assertSessionHas('success', 'Unit organisasi dipulihkan.');
        $this->assertNotSoftDeleted($child);
    }

    public function test_a_restored_unit_takes_the_current_path_of_its_parent(): void
    {
        $parent = $this->unit('NORTH');
        $child = $this->unit('TEAM-A', $parent);
        $child->delete();
        $this->actingAs($this->admin);

        OrganizationService::update($parent, ['code' => 'NORTH-1']);
        $this->assertSame('NORTH > TEAM-A', OrganizationUnit::onlyTrashed()->findOrFail($child->id)->path, 'a trashed row is skipped by the parent rename');

        $this->from('/back')->post(route('admin.organizations.restore', $child->public_id))->assertSessionHas('success', 'Unit organisasi dipulihkan.');

        $this->assertSame('NORTH-1 > TEAM-A', $child->fresh()->path);
    }

    public function test_the_employee_index_filters_by_status_including_dihapus_and_semua(): void
    {
        $this->employee(['employee_number' => 'E-ACT', 'status' => 'active']);
        $this->employee(['employee_number' => 'E-OFF', 'status' => 'inactive']);
        $this->employee(['employee_number' => 'E-END', 'status' => 'terminated']);
        $gone = $this->employee(['employee_number' => 'E-GONE', 'status' => 'active']);

        $this->actingAs($this->admin)->from('/back')->delete(route('admin.employees.destroy', $gone))
            ->assertRedirect('/back')->assertSessionHas('success', 'Karyawan dihapus.');
        $this->assertSoftDeleted($gone);

        $this->assertSame(['E-ACT', 'E-END', 'E-OFF'], $this->rows('admin.employees.index', 'employees', 'employee_number'));
        $this->assertSame(['E-ACT', 'E-END', 'E-OFF'], $this->rows('admin.employees.index', 'employees', 'employee_number', 'bogus'), 'an unknown value is the default view');
        $this->assertSame(['E-ACT'], $this->rows('admin.employees.index', 'employees', 'employee_number', 'active'));
        $this->assertSame(['E-OFF'], $this->rows('admin.employees.index', 'employees', 'employee_number', 'inactive'));
        $this->assertSame(['E-END'], $this->rows('admin.employees.index', 'employees', 'employee_number', 'terminated'));
        $this->assertSame(['E-GONE'], $this->rows('admin.employees.index', 'employees', 'employee_number', 'dihapus'));
        $this->assertSame(['E-ACT', 'E-END', 'E-GONE', 'E-OFF'], $this->rows('admin.employees.index', 'employees', 'employee_number', 'semua'));

        $props = $this->props($this->actingAs($this->admin)->get(route('admin.employees.index', ['status' => 'dihapus'])));
        $row = $props['employees']['data'][0];
        $this->assertSame('dihapus', $props['filters']['status']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d+Z$/', $row['deleted_at']);
        $this->assertNull($row['references'], 'an employee keeps its own soft delete: nothing is counted');
        $this->assertSame(['update' => true, 'delete' => true], collect($props['can'])->only(['update', 'delete'])->all());
    }

    public function test_an_employee_keeps_showing_a_deleted_vehicle(): void
    {
        $vehicle = $this->vehicle('B 5 LAMA');
        $this->employee(['vehicle_id' => $vehicle->id]);
        $vehicle->delete();

        $row = $this->props($this->actingAs($this->admin)->get(route('admin.employees.index')))['employees']['data'][0];

        $this->assertSame($vehicle->public_id, $row['vehicle_id']);
        $this->assertSame('B 5 LAMA', $row['vehicle']['plate_number']);
        $this->assertNotNull($row['vehicle']['deleted_at']);
    }

    public function test_a_user_whose_profile_was_deleted_is_pointed_to_pulihkan_instead_of_getting_a_second_profile(): void
    {
        $user = $this->member('Budi');
        $profile = $this->employee(user: $user);
        $profile->delete();
        $this->actingAs($this->admin);

        $this->postJson(route('admin.employees.store'), ['user_id' => $user->public_id])
            ->assertUnprocessable()
            ->assertJsonPath('errors.user_id.0', 'Pengguna ini sudah punya profil karyawan yang dihapus. Pilih Status "Dihapus" di daftar karyawan, lalu Pulihkan profil itu.');
        $this->assertSame(1, EmployeeProfile::withTrashed()->where('user_id', $user->id)->count());

        $this->from('/back')->post(route('admin.employees.restore', $profile->public_id))->assertSessionHas('success', 'Karyawan dipulihkan.');
        $this->postJson(route('admin.employees.store'), ['user_id' => $user->public_id])
            ->assertUnprocessable()
            ->assertJsonPath('errors.user_id.0', 'Pengguna ini sudah punya profil karyawan.');
    }

    public function test_a_deleted_profile_of_a_user_outside_the_company_is_not_disclosed(): void
    {
        $foreign = User::factory()->create(['company_id' => Company::factory()->create(['is_active' => true])->id, 'is_active' => true]);
        EmployeeProfile::factory()->create(['company_id' => $foreign->company_id, 'user_id' => $foreign->id])->delete();

        $response = $this->actingAs($this->admin)->postJson(route('admin.employees.store'), ['user_id' => $foreign->public_id])->assertUnprocessable();

        $this->assertCount(1, $response->json('errors.user_id'));
        $this->assertStringNotContainsString('dihapus', (string) $response->getContent());
    }

    public function test_the_number_of_a_deleted_employee_stays_taken(): void
    {
        $this->setManualCodes($this->company->id, true, 'employee');
        $this->employee(['employee_number' => 'EMP-OLD'])->delete();

        $this->actingAs($this->admin)->postJson(route('admin.employees.store'), ['user_id' => $this->member('Baru')->public_id, 'employee_number' => 'EMP-OLD'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['employee_number']);
    }

    public function test_a_deactivated_or_deleted_unit_or_vehicle_cannot_be_given_to_a_new_employee(): void
    {
        $user = $this->member('Calon');
        $off = $this->unit('NONAKTIF', attributes: ['is_active' => false]);
        $gone = $this->unit('HAPUS');
        $gone->delete();
        $offVehicle = $this->vehicle('B 8 NON', ['is_active' => false]);
        $goneVehicle = $this->vehicle('B 8 HAPUS');
        $goneVehicle->delete();
        $this->actingAs($this->admin);

        foreach ([$off, $gone] as $unit) {
            $this->postJson(route('admin.employees.store'), ['user_id' => $user->public_id, 'organization_id' => $unit->public_id])
                ->assertUnprocessable()->assertJsonValidationErrors(['organization_id']);
        }

        foreach ([$offVehicle, $goneVehicle] as $vehicle) {
            $this->postJson(route('admin.employees.store'), ['user_id' => $user->public_id, 'vehicle_id' => $vehicle->public_id])
                ->assertUnprocessable()->assertJsonValidationErrors(['vehicle_id']);
        }

        $this->assertDatabaseMissing('employee_profiles', ['user_id' => $user->id]);

        $live = $this->unit('AKTIF');
        $liveVehicle = $this->vehicle('B 8 AKTIF');
        $this->postJson(route('admin.employees.store'), ['user_id' => $user->public_id, 'organization_id' => $live->public_id, 'vehicle_id' => $liveVehicle->public_id])
            ->assertRedirect();
        $this->assertDatabaseHas('employee_profiles', ['user_id' => $user->id, 'organization_id' => $live->id, 'vehicle_id' => $liveVehicle->id]);
    }

    public function test_an_update_keeps_the_deactivated_unit_and_vehicle_the_employee_already_holds(): void
    {
        $held = $this->unit('HELD');
        $heldVehicle = $this->vehicle('B 7 HELD');
        $otherOff = $this->unit('OTHEROFF', attributes: ['is_active' => false]);
        $otherOffVehicle = $this->vehicle('B 7 OFF', ['is_active' => false]);
        $free = $this->unit('FREE');
        $employee = $this->employee(['organization_id' => $held->id, 'vehicle_id' => $heldVehicle->id]);
        $this->actingAs($this->admin);
        $this->post(route('admin.organizations.deactivate', $held->public_id));
        $this->post(route('admin.vehicles.deactivate', $heldVehicle->public_id));
        $this->assertFalse($held->fresh()->is_active);
        $this->assertFalse($heldVehicle->fresh()->is_active);

        $payload = fn (array $changes = []): array => [
            OptimisticLock::FIELD => OptimisticLock::version($employee->fresh()),
            'organization_id' => $held->public_id,
            'vehicle_id' => $heldVehicle->public_id,
            'status' => 'active',
            ...$changes,
        ];

        $this->putJson(route('admin.employees.update', $employee), $payload(['phone' => '0812000111']))->assertRedirect();
        $this->assertSame('0812000111', $employee->fresh()->phone);
        $this->assertSame([$held->id, $heldVehicle->id], [$employee->fresh()->organization_id, $employee->fresh()->vehicle_id]);

        $this->putJson(route('admin.employees.update', $employee), $payload(['organization_id' => $otherOff->public_id]))
            ->assertUnprocessable()->assertJsonValidationErrors(['organization_id']);
        $this->putJson(route('admin.employees.update', $employee), $payload(['vehicle_id' => $otherOffVehicle->public_id]))
            ->assertUnprocessable()->assertJsonValidationErrors(['vehicle_id']);
        $this->assertSame([$held->id, $heldVehicle->id], [$employee->fresh()->organization_id, $employee->fresh()->vehicle_id]);

        $this->putJson(route('admin.employees.update', $employee), $payload(['organization_id' => $free->public_id, 'vehicle_id' => null]))->assertRedirect();
        $this->assertSame([$free->id, null], [$employee->fresh()->organization_id, $employee->fresh()->vehicle_id]);
    }

    public function test_the_evaluation_index_filters_dihapus_and_semua(): void
    {
        $subject = $this->member('Teknisi A');
        $this->evaluation($subject, ['comment' => 'Tetap']);
        $gone = $this->evaluation($subject, ['comment' => 'Dihapus']);

        $this->actingAs($this->admin)->from('/back')->delete(route('admin.evaluations.destroy', $gone))
            ->assertRedirect('/back')->assertSessionHas('success', 'Evaluasi berhasil dihapus.');
        $this->assertSoftDeleted($gone);

        $this->assertSame(['Tetap'], $this->rows('admin.evaluations.index', 'evaluations', 'comment'));
        $this->assertSame(['Tetap'], $this->rows('admin.evaluations.index', 'evaluations', 'comment', 'bogus'), 'an unknown value is the default view');
        $this->assertSame(['Dihapus'], $this->rows('admin.evaluations.index', 'evaluations', 'comment', 'dihapus'));
        $this->assertSame(['Dihapus', 'Tetap'], $this->rows('admin.evaluations.index', 'evaluations', 'comment', 'semua'));

        $props = $this->props($this->actingAs($this->admin)->get(route('admin.evaluations.index', ['status' => 'dihapus'])));
        $row = $props['evaluations']['data'][0];
        $this->assertSame('dihapus', $props['filters']['status']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d+Z$/', $row['deleted_at']);
        $this->assertNull($row['references']);
        $this->assertSame(['update' => true, 'delete' => true], collect($props['can'])->only(['update', 'delete'])->all());
    }

    public function test_a_technician_never_lists_the_evaluations_that_were_deleted(): void
    {
        $this->withoutMiddleware(RedirectAppOnlyRoles::class);
        $technician = $this->user('technician');
        $this->evaluation($technician, ['comment' => 'Milik saya']);
        $this->evaluation($technician, ['comment' => 'Sudah dihapus'])->delete();

        foreach ([null, 'dihapus', 'semua'] as $status) {
            $this->assertSame(['Milik saya'], $this->rows('admin.evaluations.index', 'evaluations', 'comment', $status, $technician), (string) $status);
        }
    }

    public function test_pulihkan_brings_back_a_deleted_vehicle_unit_employee_and_evaluation_and_audits_it(): void
    {
        $records = [
            'vehicles' => $this->vehicle('B 6 PULIH'),
            'organizations' => $this->unit('PULIH'),
            'employees' => $this->employee(),
            'evaluations' => $this->evaluation($this->member('Teknisi B')),
        ];
        $messages = ['vehicles' => 'Kendaraan dipulihkan.', 'organizations' => 'Unit organisasi dipulihkan.', 'employees' => 'Karyawan dipulihkan.', 'evaluations' => 'Evaluasi dipulihkan.'];
        $this->actingAs($this->admin);

        foreach ($records as $base => $record) {
            $record->delete();

            $this->from('/back')->post(route("admin.{$base}.restore", $record->public_id))
                ->assertRedirect('/back')->assertSessionHas('success', $messages[$base]);

            $this->assertNotSoftDeleted($record);
            $this->assertTrue(Activity::query()->where('description', 'restored')->where('properties->id', $record->public_id)->exists(), $base);

            $this->post(route("admin.{$base}.restore", $record->public_id))->assertRedirect();
        }
    }

    public function test_deactivate_needs_manage_and_restore_needs_the_delete_permission(): void
    {
        $viewer = $this->user('people-viewer', ['vehicle.view', 'organization.view', 'employee.view', 'evaluation.view']);
        $manager = $this->user('people-manager', ['vehicle.view', 'vehicle.manage', 'organization.view', 'organization.manage', 'employee.view', 'employee.manage']);
        $updater = $this->user('evaluation-updater', ['evaluation.view', 'evaluation.update']);
        $deleter = $this->user('evaluation-deleter', ['evaluation.view', 'evaluation.delete']);

        foreach (['vehicles' => $this->vehicle('B 9 IZIN'), 'organizations' => $this->unit('IZIN')] as $base => $record) {
            $deactivate = route("admin.{$base}.deactivate", $record->public_id);
            $restore = route("admin.{$base}.restore", $record->public_id);

            $this->actingAs($viewer)->postJson($deactivate)->assertForbidden();
            $this->assertTrue($record->fresh()->is_active);
            $this->actingAs($manager)->postJson($deactivate)->assertRedirect();
            $this->assertFalse($record->fresh()->is_active);

            $record->delete();
            $this->actingAs($viewer)->postJson($restore)->assertForbidden();
            $this->assertSoftDeleted($record);
            $this->actingAs($manager)->postJson($restore)->assertRedirect();
            $this->assertNotSoftDeleted($record);
        }

        $employee = $this->employee();
        $employee->delete();
        $this->actingAs($viewer)->postJson(route('admin.employees.restore', $employee->public_id))->assertForbidden();
        $this->assertSoftDeleted($employee);
        $this->actingAs($manager)->postJson(route('admin.employees.restore', $employee->public_id))->assertRedirect();
        $this->assertNotSoftDeleted($employee);

        $evaluation = $this->evaluation($this->member('Teknisi C'));
        $evaluation->delete();
        $this->actingAs($viewer)->postJson(route('admin.evaluations.restore', $evaluation->public_id))->assertForbidden();
        $this->actingAs($updater)->postJson(route('admin.evaluations.restore', $evaluation->public_id))->assertForbidden();
        $this->assertSoftDeleted($evaluation);
        $this->actingAs($deleter)->postJson(route('admin.evaluations.restore', $evaluation->public_id))->assertRedirect();
        $this->assertNotSoftDeleted($evaluation);

        $flags = $this->props($this->actingAs($viewer)->get(route('admin.vehicles.index')))['can'];
        $this->assertSame([false, false], [$flags['update'], $flags['delete']]);
    }

    public function test_another_companys_people_answer_404_on_every_action(): void
    {
        $other = Company::factory()->create(['is_active' => true]);
        $stranger = User::factory()->create(['company_id' => $other->id, 'is_active' => true]);
        $records = [
            'vehicles' => Vehicle::factory()->create(['company_id' => $other->id]),
            'organizations' => OrganizationUnit::factory()->create(['company_id' => $other->id]),
            'employees' => EmployeeProfile::factory()->create(['company_id' => $other->id, 'user_id' => $stranger->id]),
            'evaluations' => EmployeeEvaluation::factory()->create(['company_id' => $other->id, 'employee_id' => $stranger->id]),
        ];
        $this->actingAs($this->admin);

        foreach (['vehicles', 'organizations'] as $base) {
            $this->postJson(route("admin.{$base}.deactivate", $records[$base]->public_id))->assertNotFound();
            $this->postJson(route("admin.{$base}.activate", $records[$base]->public_id))->assertNotFound();
            $this->deleteJson(route("admin.{$base}.destroy", $records[$base]->public_id))->assertNotFound();
        }

        foreach ($records as $base => $record) {
            $record->delete();
            $this->postJson(route("admin.{$base}.restore", $record->public_id))->assertNotFound();
            $this->assertSoftDeleted($record);
        }
    }

    public function test_every_reference_count_reads_a_foreign_key_index(): void
    {
        $this->actingAs($this->admin);

        foreach ([[Vehicle::query()->orderBy('plate_number'), 1], [OrganizationUnit::query()->orderBy('path'), 3]] as [$query, $declared]) {
            $subSelects = $query->withReferenceCounts()->limit(25)->explain()->where('select_type', 'DEPENDENT SUBQUERY');

            $this->assertCount($declared, $subSelects, 'one sub-select per declared reference column');
            foreach ($subSelects as $step) {
                $this->assertSame('ref', $step->type, $step->table);
                $this->assertNotNull($step->key, $step->table);
            }
        }
    }

    /** @param  list<string>  $permissions  none: the template or role of that name is used as it is; `app.web` opens the admin pages for a role of exactly these */
    private function user(string $role, array $permissions = []): User
    {
        $user = User::factory()->create(['company_id' => $this->company->id, 'email_verified_at' => now(), 'is_active' => true]);

        return $this->grantRole($user, $permissions === [] ? $role : $this->companyRole($this->company, $role, [...$permissions, 'app.web']));
    }

    private function member(string $name = 'Anggota'): User
    {
        return User::factory()->create(['company_id' => $this->company->id, 'is_active' => true, 'name' => $name.' '.++$this->sequence]);
    }

    /** @param  array<string, mixed>  $attributes */
    private function vehicle(string $plate, array $attributes = []): Vehicle
    {
        return Vehicle::factory()->create(['company_id' => $this->company->id, 'plate_number' => $plate, ...$attributes]);
    }

    /** @param  array<string, mixed>  $attributes */
    private function unit(string $code, ?OrganizationUnit $parent = null, array $attributes = []): OrganizationUnit
    {
        return OrganizationUnit::factory()->create([
            'company_id' => $this->company->id,
            'parent_id' => $parent?->id,
            'code' => $code,
            'name' => 'Unit '.$code,
            'type' => 'unit',
            'path' => ($parent === null ? '' : $parent->path.' > ').$code,
            ...$attributes,
        ]);
    }

    /** @param  array<string, mixed>  $attributes */
    private function employee(array $attributes = [], ?User $user = null): EmployeeProfile
    {
        return EmployeeProfile::factory()->create(['company_id' => $this->company->id, 'user_id' => ($user ?? $this->member())->id, ...$attributes]);
    }

    /** @param  array<string, mixed>  $attributes */
    private function evaluation(User $employee, array $attributes = []): EmployeeEvaluation
    {
        return EmployeeEvaluation::factory()->create(['company_id' => $this->company->id, 'employee_id' => $employee->id, ...$attributes]);
    }

    /** A work order the unit finished: `organization_unit_id` is stamped when the SPK is approved. */
    private function completedSpk(OrganizationUnit $unit): WorkOrder
    {
        $workOrder = WorkOrderFactory::new()->create(['company_id' => $this->company->id, 'status' => 'completed']);
        DB::table('work_orders')->where('id', $workOrder->id)->update(['organization_unit_id' => $unit->id]);

        return $workOrder;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, int> the reference counts of a row above zero, by label
     */
    private function counted(array $row): array
    {
        return collect($row['references'])->where('count', '>', 0)->pluck('count', 'label')->all();
    }

    /** @return list<string> the values of a column that an index lists for a status, sorted */
    private function rows(string $route, string $prop, string $column, ?string $status = null, ?User $as = null): array
    {
        $response = $this->actingAs($as ?? $this->admin)->get(route($route, $status === null ? [] : ['status' => $status]))->assertOk();

        return collect($this->props($response)[$prop]['data'])->pluck($column)->sort()->values()->all();
    }

    /** @return array<string, mixed> */
    private function props(TestResponse $response): array
    {
        return $response->viewData('page')['props'];
    }
}
