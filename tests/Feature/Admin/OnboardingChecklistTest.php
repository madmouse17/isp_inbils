<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\Core\Company;
use App\Models\Core\Customer;
use App\Models\Core\Location;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Service\Database\Factories\ServicePackageFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Tests\Traits\BuildsDashboardFixtures;

/**
 * F1-U01: "Mulai dari sini", the first-run checklist on the dashboard. Each step is ticked from stored data and never by
 * hand, so these tests move the data and watch the card follow. The card belongs to whoever administers the company, and
 * lists only the steps that person may open and do.
 */
class OnboardingChecklistTest extends TestCase
{
    use BuildsDashboardFixtures;
    use RefreshDatabase;

    private const KEYS = ['profile', 'map', 'pop', 'package', 'team', 'customer', 'billing'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedDashboardRoles();
    }

    public function test_fresh_tenant_admin_sees_seven_steps(): void
    {
        $admin = $this->userWithRole($this->makeCompany(), 'admin');

        $onboarding = $this->onboarding($admin);

        $this->assertSame(self::KEYS, array_column($onboarding['steps'], 'key'));
        $this->assertSame(array_fill(0, 7, false), array_column($onboarding['steps'], 'done'));
        $this->assertSame(0, $onboarding['done']);
        $this->assertSame(7, $onboarding['total']);
        $this->assertSame([
            'Lengkapi profil perusahaan',
            'Atur wilayah peta',
            'Tambah POP',
            'Atur paket layanan',
            'Tambahkan pengguna',
            'Daftarkan pelanggan pertama',
            'Atur penagihan otomatis',
        ], array_column($onboarding['steps'], 'label'));
        $this->assertSame([
            route('admin.company.profile.edit'),
            route('admin.company.settings.edit').'#peta',
            route('admin.locations.index'),
            route('admin.service-packages.index'),
            route('admin.users.index'),
            route('admin.customers.create'),
            route('admin.company.settings.edit').'#tagihan',
        ], array_column($onboarding['steps'], 'href'));
    }

    public function test_every_step_link_opens_for_a_fully_privileged_admin(): void
    {
        $admin = $this->userWithRole($this->makeCompany(), 'admin');

        foreach ($this->onboarding($admin)['steps'] as $step) {
            $this->actingAs($admin)->get(strtok($step['href'], '#'))->assertOk();
        }
    }

    public function test_steps_turn_done_from_data(): void
    {
        $company = $this->makeCompany();
        $admin = $this->userWithRole($company, 'admin');
        $settings = fn (array $add): array => array_merge($company->fresh()->settings ?? [], $add);

        $company->update(['address' => 'Jl. Merdeka 1, Bandung', 'phone' => '0221234567']);
        $this->assertSame(['profile'], $this->doneKeys($admin));

        $company->update(['settings' => $settings(['map_city_code' => '3273', 'map_lat' => -6.9, 'map_lng' => 107.6])]);
        $this->assertSame(['profile', 'map'], $this->doneKeys($admin));

        Location::factory()->create(['company_id' => $company->id, 'type' => 'pop', 'is_active' => true]);
        $this->assertSame(['profile', 'map', 'pop'], $this->doneKeys($admin));

        ServicePackageFactory::new()->create(['company_id' => $company->id, 'is_active' => true]);
        $this->assertSame(['profile', 'map', 'pop', 'package'], $this->doneKeys($admin));

        $this->staffLogin($company);
        $this->assertSame(['profile', 'map', 'pop', 'package', 'team'], $this->doneKeys($admin));

        Customer::factory()->create(['company_id' => $company->id]);
        $this->assertSame(['profile', 'map', 'pop', 'package', 'team', 'customer'], $this->doneKeys($admin));

        $onboarding = $this->onboarding($admin);
        $this->assertSame(6, $onboarding['done']);
        $this->assertSame(7, $onboarding['total']);

        $company->update(['settings' => $settings(['billing_defaults_confirmed_at' => now()->toIso8601String()])]);
        $this->assertNull($this->onboarding($admin), 'nothing is left, so the card is gone');
    }

    public function test_card_is_null_when_all_done(): void
    {
        $company = $this->makeCompany();
        $admin = $this->userWithRole($company, 'admin');
        $company->update([
            'address' => 'Jl. Merdeka 1, Bandung',
            'phone' => '0221234567',
            'settings' => ['map_lat' => -6.9, 'map_lng' => 107.6, 'billing_defaults_confirmed_at' => now()->toIso8601String()],
        ]);
        Location::factory()->create(['company_id' => $company->id, 'type' => 'pop', 'is_active' => true]);
        ServicePackageFactory::new()->create(['company_id' => $company->id, 'is_active' => true]);
        $this->staffLogin($company);
        Customer::factory()->create(['company_id' => $company->id]);

        $props = $this->actingAs($admin)->get(route('admin.dashboard'))->assertOk()->inertiaProps();

        $this->assertArrayHasKey('onboarding', $props);
        $this->assertNull($props['onboarding']);
    }

    public function test_user_without_company_manage_gets_null(): void
    {
        $company = $this->makeCompany();

        foreach (['manager', 'staff'] as $role) {
            $props = $this->actingAs($this->userWithRole($company, $role))->get(route('admin.dashboard'))->assertOk()->inertiaProps();

            $this->assertArrayHasKey('onboarding', $props, $role);
            $this->assertNull($props['onboarding'], $role);
        }

        $this->assertNull($this->onboarding($this->userWithPermissions($company, ['dashboard.view', 'users.manage', 'roles.manage'])));
    }

    public function test_data_that_does_not_count_leaves_the_step_open(): void
    {
        $company = $this->makeCompany();
        $other = $this->makeCompany();
        $admin = $this->userWithRole($company, 'admin');

        $company->update(['address' => 'Jl. Merdeka 1', 'phone' => '  ', 'settings' => ['map_city_code' => null, 'map_lat' => null, 'map_lng' => null, 'billing_defaults_confirmed_at' => '']]);
        Location::factory()->create(['company_id' => $company->id, 'type' => 'pop', 'is_active' => false]);
        Location::factory()->create(['company_id' => $company->id, 'type' => 'site', 'is_active' => true]);
        Location::factory()->create(['company_id' => $company->id, 'type' => 'pop', 'is_active' => true])->delete();
        Location::factory()->create(['company_id' => $other->id, 'type' => 'pop', 'is_active' => true]);
        ServicePackageFactory::new()->create(['company_id' => $company->id, 'is_active' => false]);
        ServicePackageFactory::new()->create(['company_id' => $company->id, 'is_active' => true])->delete();
        ServicePackageFactory::new()->create(['company_id' => $other->id, 'is_active' => true]);
        $this->staffLogin($company, active: false);
        $this->staffLogin($other);
        $login = $this->staffLogin($company);
        Customer::factory()->create(['company_id' => $company->id, 'user_id' => $login->id])->delete();
        Customer::factory()->create(['company_id' => $company->id])->delete();
        Customer::factory()->create(['company_id' => $other->id]);

        $this->assertSame([], $this->doneKeys($admin), 'an address without a phone, null map keys, a blank marker, and an inactive, other-kind, deleted or foreign POP, package, customer or staff login');
        $this->assertSame(['profile'], $this->doneAfter($admin, fn () => $company->update(['phone' => '0221234567'])));
        $this->assertSame(['profile', 'map'], $this->doneAfter($admin, fn () => $company->update(['settings' => ['map_lat' => -6.9]])), 'the point alone ticks the map step');
    }

    public function test_only_a_second_staff_login_makes_the_team(): void
    {
        $company = $this->makeCompany();
        $admin = $this->userWithRole($company, 'admin');

        $this->assertNotContains('team', $this->doneKeys($admin), 'the admin alone is not a team');

        $portal = $this->staffLogin($company);
        Customer::factory()->create(['company_id' => $company->id, 'user_id' => $portal->id]);
        $this->assertNotContains('team', $this->doneKeys($admin), "a customer's app login is not a teammate");

        $this->staffLogin($company);
        $this->assertContains('team', $this->doneKeys($admin));
    }

    /** @return array<string, array{0: list<string>, 1: list<string>}> */
    public static function permissionsAndTheirSteps(): array
    {
        $registration = ['customer.create', 'customer.address.manage', 'customer.subscription.manage', 'spk.create'];

        return [
            'company.manage alone: the three company steps' => [['company.manage'], ['profile', 'map', 'billing']],
            'a POP needs the list and the right to add one' => [['company.manage', 'location.view', 'location.create'], ['profile', 'map', 'pop', 'billing']],
            'the list without the right to add leaves the POP out' => [['company.manage', 'location.view'], ['profile', 'map', 'billing']],
            'service.manage opens and creates packages' => [['company.manage', 'service.manage'], ['profile', 'map', 'package', 'billing']],
            'users.manage opens the team page' => [['company.manage', 'users.manage'], ['profile', 'map', 'team', 'billing']],
            'registration needs all four of its permissions' => [['company.manage', ...$registration], ['profile', 'map', 'customer', 'billing']],
            'three of the four leave registration out' => [['company.manage', ...array_slice($registration, 1)], ['profile', 'map', 'billing']],
        ];
    }

    /**
     * @param  list<string>  $permissions
     * @param  list<string>  $expected
     */
    #[DataProvider('permissionsAndTheirSteps')]
    public function test_a_step_the_user_cannot_open_and_do_is_left_out_and_every_listed_link_opens(array $permissions, array $expected): void
    {
        $user = $this->userWithPermissions($this->makeCompany(), ['dashboard.view', ...$permissions]);

        $onboarding = $this->onboarding($user);

        $this->assertSame($expected, array_column($onboarding['steps'], 'key'));
        $this->assertSame(count($expected), $onboarding['total']);

        foreach ($onboarding['steps'] as $step) {
            $this->actingAs($user)->get(strtok($step['href'], '#'))->assertOk();
        }
    }

    public function test_the_checklist_does_not_cost_more_queries_with_more_rows(): void
    {
        $company = $this->makeCompany();
        $admin = $this->userWithRole($company, 'admin');

        $this->rows($company, 1);
        $this->actingAs($admin)->get(route('admin.dashboard'))->assertOk();
        $small = $this->queryCount($admin);

        $this->rows($company, 8);
        $large = $this->queryCount($admin);

        $this->assertSame($small, $large, 'the checklist asks whether, never how many');
    }

    private function rows(Company $company, int $count): void
    {
        foreach (range(1, $count) as $ignored) {
            Location::factory()->create(['company_id' => $company->id, 'type' => 'pop', 'is_active' => true]);
            ServicePackageFactory::new()->create(['company_id' => $company->id, 'is_active' => true]);
            $this->staffLogin($company);
            Customer::factory()->create(['company_id' => $company->id]);
        }
    }

    private function queryCount(User $user): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($user)->get(route('admin.dashboard'))->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    private function staffLogin(Company $company, bool $active = true): User
    {
        return User::factory()->create(['company_id' => $company->id, 'email_verified_at' => now(), 'is_active' => $active]);
    }

    /** @return array{steps: list<array{key: string, label: string, done: bool, href: string}>, done: int, total: int}|null */
    private function onboarding(User $user): ?array
    {
        return $this->actingAs($user)->get(route('admin.dashboard'))->assertOk()->inertiaProps('onboarding');
    }

    /** @return list<string> the keys of the steps the data already proves */
    private function doneKeys(User $user): array
    {
        $onboarding = $this->onboarding($user);

        return $onboarding === null
            ? self::KEYS
            : array_values(array_column(array_filter($onboarding['steps'], fn (array $step): bool => $step['done']), 'key'));
    }

    /** @return list<string> */
    private function doneAfter(User $user, callable $change): array
    {
        $change();

        return $this->doneKeys($user);
    }
}
