<?php

namespace Tests\Feature\Roles;

use App\Models\Core\Company;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Core\CompanyMembershipService;
use App\Services\Core\CompanyService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission as PackagePermission;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * The company scope of the role model (ADR-004): a role answers for the company in effect and for no other, and one
 * user instance, one permission cache and one user model serve every company.
 */
class CompanyRoleScopeTest extends TestCase
{
    use RefreshDatabase;

    private Company $alpha;

    private Company $beta;

    protected function setUp(): void
    {
        parent::setUp();

        $this->alpha = Company::factory()->create(['is_active' => true]);
        $this->beta = Company::factory()->create(['is_active' => true]);
    }

    public function test_roles_of_another_company_are_invisible(): void
    {
        $this->companyRole($this->alpha, 'Penyelia Alpha', ['customer.view']);
        $this->companyRole($this->beta, 'Penyelia Beta', ['customer.view']);

        $seen = fn (Company $company): array => CompanyService::runAs($company->id, fn (): array => Role::query()->orderBy('name')->pluck('name')->all());

        $this->assertSame(['Penyelia Alpha'], $seen($this->alpha));
        $this->assertSame(['Penyelia Beta'], $seen($this->beta));
        $this->assertSame(['Penyelia Beta'], Role::forCompany($this->beta->id)->pluck('name')->all(), 'naming the company is how code reads across companies');
        $this->assertSame(['Penyelia Alpha', 'Penyelia Beta'], Role::withoutCompany()->orderBy('name')->pluck('name')->all());
    }

    public function test_no_company_context_matches_no_role(): void
    {
        $this->companyRole($this->alpha, 'Penyelia', ['customer.view']);

        $this->assertNull(CompanyService::currentId());
        $this->assertSame(0, Role::query()->count());
        $this->assertNull(Role::query()->where('name', 'Penyelia')->first());
        $this->assertNull(Role::forTemplate('admin'));
    }

    public function test_a_role_is_created_in_the_company_in_effect_and_never_without_one(): void
    {
        $created = CompanyService::runAs($this->alpha->id, fn (): Role => Role::query()->create(['name' => 'Baru', 'guard_name' => 'web']));

        $this->assertSame($this->alpha->id, $created->company_id);
        $this->assertTrue(Str::isUlid($created->public_id));

        $this->expectException(HttpException::class);
        Role::query()->create(['name' => 'Tanpa Perusahaan', 'guard_name' => 'web']);
    }

    public function test_company_and_template_are_not_mass_assignable(): void
    {
        $created = CompanyService::runAs($this->alpha->id, fn (): Role => Role::query()->create([
            'name' => 'Dipaksa', 'guard_name' => 'web', 'company_id' => $this->beta->id, 'template_key' => 'admin',
        ]));

        $this->assertSame($this->alpha->id, $created->company_id, 'the company comes from the context, never from input');
        $this->assertNull($created->template_key);
    }

    public function test_for_template_finds_the_copy_of_the_current_company_by_its_key_even_after_a_rename(): void
    {
        $copies = $this->companyRoles($this->alpha);
        $this->companyRoles($this->beta);
        $copies['customer']->forceFill(['name' => 'Akun Pelanggan'])->save();

        $found = CompanyService::runAs($this->alpha->id, fn (): ?Role => Role::forTemplate('customer'));

        $this->assertTrue($found?->is($copies['customer']));
        $this->assertSame('Akun Pelanggan', $found->name);
        $this->assertNull(CompanyService::runAs($this->alpha->id, fn (): ?Role => Role::forTemplate('tidak-ada')));
    }

    public function test_sync_roles_in_one_company_keeps_the_roles_of_the_other(): void
    {
        $user = $this->memberOfBoth();

        CompanyService::runAs($this->alpha->id, fn () => $user->syncRoles([]));

        $this->assertSame([], $this->roleKeysOf($user, $this->alpha));
        $this->assertSame(['manager'], $this->roleKeysOf($user, $this->beta));
    }

    public function test_one_user_instance_answers_per_company_across_run_as(): void
    {
        $user = $this->memberOfBoth();
        $holds = fn (Company $company, string $permission): bool => CompanyService::runAs($company->id, fn (): bool => $user->can($permission));

        $this->assertTrue($holds($this->alpha, 'inventory.stock.receive'), 'staff in alpha');
        $this->assertFalse($holds($this->alpha, 'inventory.request.approve'));
        $this->assertTrue($holds($this->beta, 'inventory.request.approve'), 'manager in beta');
        $this->assertFalse($holds($this->beta, 'inventory.stock.receive'));
        $this->assertTrue($holds($this->alpha, 'inventory.stock.receive'), 'and back, on the same instance');
        $this->assertSame(['Staf'], CompanyService::runAs($this->alpha->id, fn (): array => $user->getRoleNames()->all()));
        $this->assertSame(['Manajer'], CompanyService::runAs($this->beta->id, fn (): array => $user->getRoleNames()->all()));
    }

    public function test_a_relation_restored_without_company_is_reloaded_inside_run_as(): void
    {
        $user = $this->memberOfBoth();
        $payload = CompanyService::runAs($this->alpha->id, function () use ($user): RestoredUserPayload {
            $user->load('roles');

            return new RestoredUserPayload($user);
        });

        // A queue worker restores the model before the job enters its company: the relation is loaded with no company.
        $restored = unserialize(serialize($payload))->user;

        $this->assertTrue(CompanyService::runAs($this->alpha->id, fn (): bool => $restored->can('inventory.stock.receive')));
        $this->assertTrue(CompanyService::runAs($this->beta->id, fn (): bool => $restored->can('inventory.request.approve')));
        $this->assertFalse($restored->can('inventory.request.approve'), 'with no company there is no role to hold it');
    }

    public function test_permission_cache_holds_roles_of_every_company(): void
    {
        $this->companyRole($this->alpha, 'Penyelia Alpha', ['customer.view']);
        $this->companyRole($this->beta, 'Penyelia Beta', ['customer.view']);
        $registrar = app(PermissionRegistrar::class);
        $registrar->forgetCachedPermissions();

        // The package's own Permission class swaps the registrar's class: the entry must still hold every company's roles.
        PackagePermission::findOrCreate('scope.probe', 'web');

        $companies = CompanyService::runAs($this->alpha->id, fn (): array => $registrar->getPermissions(['name' => 'customer.view'])->first()->roles->pluck('company_id')->unique()->sort()->values()->all());

        $this->assertSame([$this->alpha->id, $this->beta->id], $companies);
        $this->assertEqualsCanonicalizing(
            [$this->alpha->id, $this->beta->id],
            CompanyService::runAs($this->alpha->id, fn (): array => Permission::query()->where('name', 'customer.view')->firstOrFail()->roles->pluck('company_id')->unique()->all()),
            'the relation of the permission model is not scoped either',
        );
    }

    public function test_the_same_role_name_may_exist_in_two_companies_but_not_twice_in_one(): void
    {
        $this->companyRole($this->alpha, 'Penyelia', ['customer.view']);
        $this->companyRole($this->beta, 'Penyelia', ['ticket.view']);

        $this->assertSame(2, Role::withoutCompany()->where('name', 'Penyelia')->count());

        $this->expectException(UniqueConstraintViolationException::class);
        CompanyService::runAs($this->alpha->id, fn (): Role => Role::query()->create(['name' => 'penyelia', 'guard_name' => 'web']));
    }

    public function test_a_company_has_one_copy_of_a_template(): void
    {
        $this->companyRoles($this->alpha);

        $this->expectException(UniqueConstraintViolationException::class);
        DB::table('roles')->insert([
            'public_id' => Str::lower((string) Str::ulid()), 'name' => 'Admin Kedua', 'guard_name' => 'web',
            'company_id' => $this->alpha->id, 'template_key' => 'admin', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_with_permission_in_names_holders_of_the_given_company_only(): void
    {
        $inAlpha = $this->grantPermissions(User::factory()->create(['company_id' => $this->alpha->id]), ['ticket.view'], $this->alpha);
        $inBeta = $this->grantPermissions(User::factory()->create(['company_id' => $this->beta->id]), ['ticket.view'], $this->beta);
        $direct = User::factory()->create(['company_id' => $this->beta->id]);
        $direct->givePermissionTo('ticket.view');
        $other = $this->grantPermissions(User::factory()->create(['company_id' => $this->alpha->id]), ['customer.view'], $this->alpha);
        // Holds it in beta through a role, and is homed in alpha: a role of beta counts for beta only.
        $traveller = $this->grantPermissions(User::factory()->create(['company_id' => $this->alpha->id]), ['ticket.view'], $this->beta);

        $holders = fn (Company $company, string|array $permissions): array => User::query()->withPermissionIn($permissions, $company->id)->orderBy('id')->pluck('id')->all();

        // A direct permission is the user's own: it holds in every company.
        $this->assertSame([$inAlpha->id, $direct->id], $holders($this->alpha, 'ticket.view'));
        $this->assertSame([$inBeta->id, $direct->id, $traveller->id], $holders($this->beta, 'ticket.view'));
        $this->assertSame([$inAlpha->id, $direct->id, $other->id], $holders($this->alpha, ['ticket.view', 'customer.view']), 'any of the permissions');
        $this->assertSame([], $holders($this->alpha, 'billing.cancel'));
        $this->assertSame([], $holders($this->alpha, []));
        $this->assertNull(CompanyService::currentId(), 'it needs no company in effect');
    }

    private function memberOfBoth(): User
    {
        $user = User::factory()->create(['company_id' => $this->alpha->id]);
        app(CompanyMembershipService::class)->grant($user, $this->alpha, true);
        app(CompanyMembershipService::class)->grant($user, $this->beta);
        $this->grantRole($user, 'staff', $this->alpha);
        $this->grantRole($user, 'manager', $this->beta);

        return $user;
    }

    /** @return list<string> the template keys of the roles the user holds in the company */
    private function roleKeysOf(User $user, Company $company): array
    {
        return DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('model_has_roles.model_id', $user->id)
            ->where('roles.company_id', $company->id)
            ->pluck('roles.template_key')
            ->all();
    }
}

/** What a queued job holds: a user, serialized by id and restored with the relations it had. */
final class RestoredUserPayload
{
    use SerializesModels;

    public function __construct(public User $user) {}
}
