<?php

namespace Tests\Feature\Platform;

use App\Models\Core\Company;
use App\Models\Role;
use App\Models\User;
use App\Support\PublicId;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * A platform operator adds a subsidiary to a tenant through CompanyHierarchyService, with the defaults every new
 * company gets, and may enrol members of that tenant in it. Nothing outside the tenant is reachable.
 */
class TenantSubsidiaryTest extends TestCase
{
    use RefreshDatabase;
    use TenantFixtures;

    /** @param  array<string, mixed>  $overrides */
    private function payload(array $overrides = []): array
    {
        return ['name' => 'Alpha Selatan', 'code' => 'ALS', ...$overrides];
    }

    public function test_only_a_platform_admin_with_a_verified_second_factor_can_add_a_subsidiary(): void
    {
        $tenant = $this->buildTenant('ALP', 'Alpha Net');
        $url = route('platform.companies.subsidiaries.store', $tenant['root']);

        $this->asTenantVisitor()->post($url, $this->payload())->assertRedirect(route('platform.login'));
        $this->actingAs($tenant['admin'])->post($url, $this->payload())->assertRedirect(route('platform.login'));
        $this->signInPlatformAdmin($this->operator, ['platform.two_factor_verified_at' => null])->post($url, $this->payload())->assertRedirect(route('platform.login'));

        $this->assertDatabaseMissing('companies', ['code' => 'ALS']);
    }

    public function test_a_subsidiary_is_created_under_the_tenant_with_its_heads_currency_and_timezone_and_the_defaults(): void
    {
        $tenant = $this->buildTenant('ALP', 'Alpha Net');
        $tenant['root']->forceFill(['currency' => 'SGD', 'timezone' => 'Asia/Makassar'])->save();
        $other = $this->buildTenant('BET', 'Beta Link');

        $this->asOperator(false)->post(route('platform.companies.subsidiaries.store', $tenant['root']), $this->payload())
            ->assertRedirect(route('platform.companies.show', $tenant['root']))
            ->assertSessionHas('success');

        $sub = Company::query()->where('code', 'ALS')->firstOrFail();
        $this->assertSame('Alpha Selatan', $sub->name);
        $this->assertSame($tenant['root']->id, $sub->parent_company_id);
        $this->assertTrue($sub->is_active);
        $this->assertNull($sub->suspended_at);
        $this->assertSame('SGD', $sub->currency);
        $this->assertSame('Asia/Makassar', $sub->timezone);
        $this->assertSame('S$', $sub->settings['currency_symbol']);
        $this->assertSame(4, DB::table('units')->where('company_id', $sub->id)->count(), 'the defaults of every new company');

        $this->assertSame(2, Company::query()->where('parent_company_id', $tenant['root']->id)->count());
        $this->assertSame(1, Company::query()->where('parent_company_id', $other['root']->id)->count(), 'the other tenant is untouched');
    }

    public function test_the_operator_may_choose_a_currency_and_timezone_for_the_subsidiary(): void
    {
        $tenant = $this->buildTenant('ALP', 'Alpha Net');

        $this->asOperator(false)->post(route('platform.companies.subsidiaries.store', $tenant['root']), $this->payload(['currency' => 'USD', 'timezone' => 'Asia/Jayapura']))->assertRedirect();

        $sub = Company::query()->where('code', 'ALS')->firstOrFail();
        $this->assertSame('USD', $sub->currency);
        $this->assertSame('Asia/Jayapura', $sub->timezone);
    }

    public function test_members_of_the_tenant_are_enrolled_in_the_subsidiary_and_keep_their_home(): void
    {
        $tenant = $this->buildTenant('ALP', 'Alpha Net');

        $this->asOperator(false)->post(route('platform.companies.subsidiaries.store', $tenant['root']), $this->payload([
            'member_ids' => [$tenant['staff']->public_id, $tenant['branch']->public_id],
        ]))->assertRedirect();

        $sub = Company::query()->where('code', 'ALS')->firstOrFail();
        foreach ([$tenant['staff'], $tenant['branch']] as $member) {
            $this->assertSame(1, DB::table('company_user')->where(['company_id' => $sub->id, 'user_id' => $member->id, 'is_default' => 0])->count());
            $this->assertNotSame($sub->id, $member->fresh()->company_id, 'home company unchanged');
        }
        $this->assertSame(0, DB::table('company_user')->where(['company_id' => $sub->id, 'user_id' => $tenant['admin']->id])->count(), 'not asked for: not enrolled');
    }

    public function test_a_member_must_be_homed_in_this_tenant(): void
    {
        $tenant = $this->buildTenant('ALP', 'Alpha Net');
        $other = $this->buildTenant('BET', 'Beta Link');
        $homeless = User::factory()->create(['company_id' => null]);
        $staff = $tenant['staff']->public_id;

        $cases = [
            'a user of another tenant' => [[$other['admin']->public_id], 'member_ids.0'],
            'a user with no home company' => [[$homeless->public_id], 'member_ids.0'],
            'an id that does not exist' => [[PublicId::new()], 'member_ids.0'],
            'an integer id of a member' => [[$tenant['staff']->id], 'member_ids.0'],
            'something that is no id' => [['abc'], 'member_ids.0'],
            'the same member twice' => [[$staff, $staff], 'member_ids.0'],
            'one stranger among members' => [[$staff, $other['staff']->public_id], 'member_ids.1'],
            'a value that is no list' => ['not-a-list', 'member_ids'],
        ];

        foreach ($cases as $case => [$ids, $errorKey]) {
            $this->asOperator(false)->post(route('platform.companies.subsidiaries.store', $tenant['root']), $this->payload(['member_ids' => $ids]));

            $this->assertTrue(session('errors')?->has($errorKey) ?? false, "case: $case");
        }

        $this->assertDatabaseMissing('companies', ['code' => 'ALS']);
        $this->assertSame(0, DB::table('company_user')->where('user_id', $other['admin']->id)->where('company_id', '<>', $other['root']->id)->count());
    }

    public function test_the_form_is_validated(): void
    {
        $tenant = $this->buildTenant('ALP', 'Alpha Net');
        Company::factory()->create(['code' => 'TAKEN']);
        $invalid = [
            ['name' => ''],
            ['name' => str_repeat('n', 256)],
            ['code' => ''],
            ['code' => 'TAKEN'],
            ['code' => 'taken'],
            ['code' => '_x'],
            ['code' => 'a b'],
            ['code' => str_repeat('c', 51)],
            ['currency' => 'JPY'],
            ['timezone' => 'Mars/Base'],
        ];

        foreach ($invalid as $change) {
            $this->asOperator(false)->post(route('platform.companies.subsidiaries.store', $tenant['root']), $this->payload($change))->assertSessionHasErrors(array_key_first($change));
        }

        $this->assertDatabaseMissing('companies', ['code' => 'ALS']);
    }

    public function test_a_suspended_tenant_gets_no_new_active_subsidiary(): void
    {
        $tenant = $this->buildTenant('ALP', 'Alpha Net');
        $tenant['root']->forceFill(['is_active' => false, 'suspended_at' => now()])->save();

        $this->asOperator(false)->post(route('platform.companies.subsidiaries.store', $tenant['root']), $this->payload())
            ->assertSessionHasErrors(['lifecycle' => 'Perusahaan sedang ditangguhkan: aktifkan kembali sebelum menambah anak perusahaan.']);

        $this->assertDatabaseMissing('companies', ['code' => 'ALS']);
    }

    public function test_the_subsidiary_is_audited_for_the_platform(): void
    {
        $tenant = $this->buildTenant('ALP', 'Alpha Net');

        $this->asOperator(false)->post(route('platform.companies.subsidiaries.store', $tenant['root']), $this->payload(['member_ids' => [$tenant['staff']->public_id]]))->assertRedirect();

        $sub = Company::query()->where('code', 'ALS')->firstOrFail();
        $row = Activity::query()->where('log_name', 'platform')->where('description', 'tenant.subsidiary_created')->sole();

        $this->assertSame($this->operator->id, $row->causer_id);
        $this->assertSame($this->operator::class, $row->causer_type);
        $this->assertSame($sub->id, $row->subject_id);
        $this->assertSame($tenant['root']->id, $row->properties['parent_company_id']);
        $this->assertSame('ALS', $row->properties['code']);
        $this->assertSame([$tenant['staff']->id], $row->properties['member_ids']);
        $this->assertSame([], $row->properties['members_without_roles']);
    }

    public function test_the_subsidiary_gets_the_templates_and_members_get_the_copy_of_each_template_role_they_hold_at_home(): void
    {
        $tenant = $this->buildTenant('ALP', 'Alpha Net');

        $this->asOperator(false)->post(route('platform.companies.subsidiaries.store', $tenant['root']), $this->payload([
            'member_ids' => [$tenant['staff']->public_id, $tenant['branch']->public_id],
        ]))->assertRedirect();

        $sub = Company::query()->where('code', 'ALS')->firstOrFail();
        $copies = Role::withoutCompany()->where('company_id', $sub->id)->pluck('id', 'template_key');
        $this->assertSame(['admin', 'customer', 'finance', 'manager', 'noc', 'staff', 'technician', 'warehouse'], $copies->keys()->sort()->values()->all());

        $holds = fn (User $user, string $key): bool => DB::table('model_has_roles')->where(['model_id' => $user->id, 'role_id' => $copies[$key], 'model_type' => User::class])->exists();
        $this->assertTrue($holds($tenant['staff'], 'staff'), 'a Staf at home is a Staf in the subsidiary');
        $this->assertTrue($holds($tenant['branch'], 'manager'), 'and a manager homed in another company of the tenant keeps his template');
        $this->assertFalse($holds($tenant['staff'], 'admin'));
        $this->assertSame(0, DB::table('model_has_roles')->where('model_id', $tenant['admin']->id)->whereIn('role_id', $copies->values())->count(), 'not enrolled: no role');

        $homeRoles = DB::table('model_has_roles')->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('model_has_roles.model_id', $tenant['staff']->id)->pluck('roles.company_id')->all();
        $this->assertEqualsCanonicalizing([$tenant['root']->id, $sub->id], $homeRoles, 'the home roles stay and one copy is added');
    }

    public function test_a_member_with_only_custom_roles_gets_none_and_the_audit_row_names_him(): void
    {
        $tenant = $this->buildTenant('ALP', 'Alpha Net');
        $custom = User::factory()->create(['company_id' => $tenant['root']->id, 'email_verified_at' => now(), 'is_active' => true]);
        $this->grantRole($custom, $this->companyRole($tenant['root'], 'Penyelia', ['customer.view']), $tenant['root']);

        $this->asOperator(false)->post(route('platform.companies.subsidiaries.store', $tenant['root']), $this->payload([
            'member_ids' => [$custom->public_id, $tenant['staff']->public_id],
        ]))->assertRedirect()->assertSessionHas('success', 'Anak perusahaan dibuat. Anggota ini belum punya peran di anak perusahaan baru dan perlu diberi peran di Pengguna: '.$custom->name.'.');

        $sub = Company::query()->where('code', 'ALS')->firstOrFail();
        $this->assertSame(0, DB::table('model_has_roles')->join('roles', 'roles.id', '=', 'model_has_roles.role_id')->where('model_has_roles.model_id', $custom->id)->where('roles.company_id', $sub->id)->count());
        $row = Activity::query()->where('log_name', 'platform')->where('description', 'tenant.subsidiary_created')->sole();
        $this->assertSame([$custom->id], $row->properties['members_without_roles']);
    }
}
