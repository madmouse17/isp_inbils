<?php

namespace Tests\Feature\Integrity;

use App\Models\Core\Company;
use App\Models\Core\EmployeeProfile;
use App\Models\User;
use App\Services\Core\CompanyService;
use App\Support\OptimisticLock;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

/**
 * Item 11 of integrity-hardening for the people and company forms: an employee or settings save carries the version
 * the form was rendered from; a save from an older copy is refused and writes nothing. The settings merge happens on
 * the locked fresh row, so a concurrent writer's keys survive.
 */
class SettingsEditConflictTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        $this->seed(RolePermissionSeeder::class);
        app()['cache']->forget('spatie.permission.cache');

        $this->company = Company::factory()->create([
            'is_active' => true,
            'settings' => ['tax_ppn_rate' => 11],
            'updated_at' => now()->subMinute(),
        ]);
        $this->admin = User::factory()->create(['company_id' => $this->company->id, 'email_verified_at' => now(), 'is_active' => true]);
        $this->grantRole($this->admin, 'admin');
    }

    public function test_an_employee_save_from_an_older_copy_is_refused(): void
    {
        $profile = $this->profile();
        $url = route('admin.employees.update', $profile);
        $rendered = OptimisticLock::version($profile);

        $this->actingAs($this->admin)->put($url, ['phone' => '0811', 'status' => 'active', OptimisticLock::FIELD => $rendered])
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->putJson($url, ['phone' => '0822', 'status' => 'active', OptimisticLock::FIELD => $rendered])
            ->assertStatus(409)
            ->assertExactJson(['message' => OptimisticLock::MESSAGE, 'error' => 'conflict']);

        $this->withHeader('X-Inertia', 'true')
            ->put($url, ['phone' => '0833', 'status' => 'active', OptimisticLock::FIELD => $rendered])
            ->assertStatus(303)
            ->assertSessionHasErrors(['conflict' => OptimisticLock::MESSAGE])
            ->assertSessionHas('error', OptimisticLock::MESSAGE);

        $this->assertSame('0811', $profile->fresh()->phone);
    }

    public function test_an_employee_save_from_the_current_copy_is_stored(): void
    {
        $profile = $this->profile();

        $this->actingAs($this->admin)
            ->put(route('admin.employees.update', $profile), ['phone' => '0899', 'status' => 'inactive', OptimisticLock::FIELD => OptimisticLock::version($profile)])
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('0899', $profile->fresh()->phone);
        $this->assertSame('inactive', $profile->fresh()->status);
    }

    public function test_an_employee_save_without_a_version_is_a_validation_error_and_changes_nothing(): void
    {
        $profile = $this->profile();

        $this->actingAs($this->admin)->putJson(route('admin.employees.update', $profile), ['phone' => '0899', 'status' => 'active'])
            ->assertUnprocessable()->assertJsonValidationErrors(OptimisticLock::FIELD);

        $this->assertSame('0800', $profile->fresh()->phone);
    }

    public function test_the_employee_list_hands_out_the_version_to_send_back(): void
    {
        $profile = $this->profile();

        $this->actingAs($this->admin)->get(route('admin.employees.index'))
            ->assertInertia(fn (Assert $page) => $page->where('employees.data.0.updated_at', OptimisticLock::version($profile)));
    }

    public function test_a_settings_save_from_an_older_copy_is_refused(): void
    {
        $url = route('admin.company.settings.update');
        $rendered = OptimisticLock::version($this->company);

        $this->actingAs($this->admin)->put($url, ['settings' => ['tax_ppn_rate' => 12], OptimisticLock::FIELD => $rendered])
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->putJson($url, ['settings' => ['tax_ppn_rate' => 13], OptimisticLock::FIELD => $rendered])
            ->assertStatus(409)
            ->assertExactJson(['message' => OptimisticLock::MESSAGE, 'error' => 'conflict']);

        $this->withHeader('X-Inertia', 'true')
            ->put($url, ['settings' => ['tax_ppn_rate' => 14], OptimisticLock::FIELD => $rendered])
            ->assertStatus(303)
            ->assertSessionHasErrors(['conflict' => OptimisticLock::MESSAGE])
            ->assertSessionHas('error', OptimisticLock::MESSAGE);

        $this->assertSame(12, $this->company->fresh()->settings['tax_ppn_rate']);
    }

    public function test_a_settings_save_without_a_version_is_a_validation_error_and_changes_nothing(): void
    {
        $this->actingAs($this->admin)->putJson(route('admin.company.settings.update'), ['settings' => ['tax_ppn_rate' => 12]])
            ->assertUnprocessable()->assertJsonValidationErrors(OptimisticLock::FIELD);

        $this->assertSame(11, $this->company->fresh()->settings['tax_ppn_rate']);
    }

    public function test_the_settings_page_hands_out_the_version_to_send_back(): void
    {
        $this->actingAs($this->admin)->get(route('admin.company.settings.edit'))
            ->assertInertia(fn (Assert $page) => $page->where('version', OptimisticLock::version($this->company)));
    }

    public function test_the_settings_merge_keeps_the_keys_a_concurrent_writer_stored(): void
    {
        $this->actingAs($this->admin);
        $version = OptimisticLock::version($this->company);
        CompanyService::current(); // this request now holds the company as it was before the other writer

        // The other writer commits a key of its own; the row's version is not what this test is about.
        DB::table('companies')->where('id', $this->company->id)->update(['settings' => json_encode(['tax_ppn_rate' => 11, 'whatsapp_monthly_cap' => 500])]);

        $saved = CompanyService::updateSettings(['invoice_due_days' => 21], $version);

        $this->assertEquals(['tax_ppn_rate' => 11, 'whatsapp_monthly_cap' => 500, 'invoice_due_days' => 21], $saved->settings);
        $this->assertEquals($saved->settings, $this->company->fresh()->settings);
    }

    public function test_the_service_refuses_a_stale_version_and_skips_the_compare_without_one(): void
    {
        $this->actingAs($this->admin);

        try {
            CompanyService::updateSettings(['tax_ppn_rate' => 12], '2000-01-01T00:00:00.000Z');
            $this->fail('A stale version must be refused.');
        } catch (ConflictHttpException $e) {
            $this->assertSame(OptimisticLock::MESSAGE, $e->getMessage());
        }
        $this->assertSame(11, $this->company->fresh()->settings['tax_ppn_rate']);

        CompanyService::updateSettings(['tax_ppn_rate' => 12]);
        $this->assertSame(12, $this->company->fresh()->settings['tax_ppn_rate']);
    }

    /** A profile whose version is a minute old, so that any write moves it to another second. */
    private function profile(): EmployeeProfile
    {
        return EmployeeProfile::factory()->create([
            'company_id' => $this->company->id,
            'user_id' => User::factory()->create(['company_id' => $this->company->id, 'is_active' => true])->id,
            'phone' => '0800',
            'status' => 'active',
            'updated_at' => now()->subMinute(),
        ])->fresh();
    }
}
