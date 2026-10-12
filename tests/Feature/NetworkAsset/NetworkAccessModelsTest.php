<?php

namespace Tests\Feature\NetworkAsset;

use App\Models\Core\Company;
use App\Models\Core\ServiceSubscription;
use App\Services\Core\CompanyService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\NetworkAsset\Database\Factories\NetworkAssetFactory;
use Modules\NetworkAsset\Models\NasDevice;
use Modules\NetworkAsset\Models\NetworkAccount;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;
use Tests\Traits\BuildsSubscriptionBilling;
use Tests\Traits\CreatesCompanyUser;

class NetworkAccessModelsTest extends TestCase
{
    use BuildsSubscriptionBilling;
    use CreatesCompanyUser;
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = $this->createCompanyUser()->company;
    }

    private function nasDevice(?Company $company = null): NasDevice
    {
        return NasDevice::factory()->create(['company_id' => ($company ?? $this->company)->id]);
    }

    private function account(?Company $company = null, array $attributes = []): NetworkAccount
    {
        $company ??= $this->company;

        return NetworkAccount::factory()->create($attributes + [
            'company_id' => $company->id,
            'subscription_id' => $this->subscriptionIn($company)->id,
        ]);
    }

    public function test_both_secrets_are_stored_encrypted_and_read_back_in_clear(): void
    {
        $nas = NasDevice::factory()->create(['company_id' => $this->company->id, 'radius_secret' => 'fake-radius-plain-9']);
        $account = $this->account(attributes: ['password' => 'fake-subscriber-plain-9']);

        $rawNas = DB::table('nas_devices')->where('id', $nas->id)->value('radius_secret');
        $rawAccount = DB::table('network_accounts')->where('id', $account->id)->value('password');

        $this->assertStringNotContainsString('fake-radius-plain-9', $rawNas);
        $this->assertStringNotContainsString('fake-subscriber-plain-9', $rawAccount);
        $this->assertSame('fake-radius-plain-9', $nas->fresh()->radius_secret);
        $this->assertSame('fake-subscriber-plain-9', $account->fresh()->password);
    }

    public function test_neither_secret_appears_in_arrays_or_json(): void
    {
        $nas = NasDevice::factory()->create(['company_id' => $this->company->id, 'radius_secret' => 'fake-radius-plain-9']);
        $account = $this->account(attributes: ['password' => 'fake-subscriber-plain-9']);
        $account->load('subscription');

        foreach ([$nas->toArray(), $account->toArray(), json_decode($nas->toJson(), true), json_decode($account->toJson(), true), json_decode(json_encode(collect([$nas, $account])), true)] as $serialised) {
            $encoded = json_encode($serialised);
            $this->assertStringNotContainsString('fake-radius-plain-9', $encoded);
            $this->assertStringNotContainsString('fake-subscriber-plain-9', $encoded);
        }
        $this->assertArrayNotHasKey('radius_secret', $nas->toArray());
        $this->assertArrayNotHasKey('password', $account->toArray());
    }

    public function test_queries_without_a_company_context_return_nothing_and_creates_abort(): void
    {
        $nas = $this->nasDevice();
        $this->account();
        CompanyService::useCompanyForTests(null);

        $this->assertSame(0, NasDevice::query()->count());
        $this->assertSame(0, NetworkAccount::query()->count());
        $this->assertNotNull(NasDevice::withoutCompany()->find($nas->id));

        foreach ([
            fn () => NasDevice::query()->create(['network_asset_id' => $nas->network_asset_id, 'nasname' => '192.0.2.250', 'radius_secret' => 'fake-secret-1']),
            fn () => NetworkAccount::query()->create(['subscription_id' => 1, 'username' => 'abc@xyz', 'password' => 'secret1']),
        ] as $create) {
            try {
                $create();
                $this->fail('Expected a 403 abort.');
            } catch (HttpException $e) {
                $this->assertSame(403, $e->getStatusCode());
            }
        }
    }

    public function test_another_companys_rows_are_invisible(): void
    {
        $other = Company::factory()->create(['is_active' => true]);
        $foreignNas = $this->nasDevice($other);
        $foreignAccount = $this->account($other);

        $this->assertNull(NasDevice::query()->find($foreignNas->id));
        $this->assertNull(NetworkAccount::query()->find($foreignAccount->id));
        $this->assertSame(0, NasDevice::query()->count());
        CompanyService::useCompanyForTests((int) $other->id);
        $this->assertNotNull(NasDevice::query()->find($foreignNas->id));
    }

    public function test_usernames_are_unique_across_all_companies(): void
    {
        $this->account(attributes: ['username' => 'alice@abc']);

        $this->assertQueryFails(fn () => $this->account(attributes: ['username' => 'alice@abc']));

        $other = Company::factory()->create(['is_active' => true]);
        $this->assertQueryFails(fn () => $this->account($other, ['username' => 'alice@abc']));
    }

    public function test_the_default_username_carries_the_realm_of_its_company(): void
    {
        $account = $this->account();

        $this->assertStringEndsWith('@'.strtolower($this->company->code), $account->username);
    }

    public function test_one_account_per_subscription_one_nas_per_router_asset_and_one_company_per_nasname(): void
    {
        $account = $this->account();
        $nas = $this->nasDevice();

        $this->assertQueryFails(fn () => NetworkAccount::factory()->create([
            'company_id' => $this->company->id,
            'subscription_id' => $account->subscription_id,
        ]));
        $this->assertQueryFails(fn () => NasDevice::factory()->create([
            'company_id' => $this->company->id,
            'network_asset_id' => $nas->network_asset_id,
        ]));
        $this->assertQueryFails(fn () => NasDevice::factory()->create([
            'company_id' => Company::factory()->create(['is_active' => true])->id,
            'nasname' => $nas->nasname,
        ]));
    }

    public function test_enum_columns_reject_unknown_values(): void
    {
        $this->assertQueryFails(fn () => $this->account(attributes: ['service_type' => 'l2tp']));
        $account = $this->account();

        $this->assertQueryFails(fn () => DB::table('network_accounts')->where('id', $account->id)->update(['sync_status' => 'done']));
    }

    public function test_status_and_provisioning_columns_are_not_mass_assignable(): void
    {
        $account = $this->account();
        $nas = $this->nasDevice();

        $account->update([
            'sync_status' => 'failed',
            'provisioned_group' => 'abc/home-20m',
            'provisioned_username' => 'alice@abc',
            'pending_disconnect_at' => now(),
            'last_error' => 'x',
            'company_id' => 999,
        ]);
        $nas->update(['radius_synced_at' => now(), 'radius_nasname' => '203.0.113.1', 'last_error' => 'x']);

        $account = $account->fresh();
        $this->assertSame('pending', $account->sync_status);
        $this->assertNull($account->provisioned_group);
        $this->assertNull($account->provisioned_username);
        $this->assertNull($account->pending_disconnect_at);
        $this->assertNull($account->last_error);
        $this->assertSame($this->company->id, $account->company_id);
        $nas = $nas->fresh();
        $this->assertNull($nas->radius_synced_at);
        $this->assertNull($nas->radius_nasname);
        $this->assertNull($nas->last_error);
    }

    public function test_engine_columns_are_written_with_force_fill_and_cast(): void
    {
        $nas = $this->nasDevice();

        $nas->forceFill(['radius_synced_at' => '2026-10-15 08:00:00', 'radius_nasname' => '192.0.2.99'])->save();

        $nas = $nas->fresh();
        $this->assertSame('2026-10-15 08:00:00', $nas->radius_synced_at->format('Y-m-d H:i:s'));
        $this->assertSame('192.0.2.99', $nas->radius_nasname);
        $this->assertSame(1700, $nas->coa_port);
    }

    public function test_the_realm_is_the_lower_cased_company_code_or_null_when_it_does_not_fit(): void
    {
        $nas = $this->nasDevice();

        $this->assertSame(strtolower($this->company->code), $nas->realm);

        foreach (['ACME_CORP', '', str_repeat('a', 25), 'Ünï'] as $code) {
            $this->company->forceFill(['code' => $code])->save();
            $this->assertNull($nas->fresh()->realm, "code {$code}");
        }

        $this->company->forceFill(['code' => 'North-Isp-1'])->save();
        $this->assertSame('north-isp-1', $nas->fresh()->realm);
    }

    public function test_defaults_and_relations(): void
    {
        $account = $this->account();
        $nas = $this->nasDevice();

        $this->assertSame('pppoe', $account->fresh()->service_type);
        $this->assertSame('pending', $account->fresh()->sync_status);
        $this->assertSame($nas->nasname, $nas->networkAsset->management_ip);
        $this->assertSame('router', $nas->networkAsset->asset_type);
        $this->assertNull($nas->radius_synced_at);
    }

    public function test_the_subscription_resolves_even_after_it_is_soft_deleted(): void
    {
        $account = $this->account();
        ServiceSubscription::query()->whereKey($account->subscription_id)->firstOrFail()->delete();

        $this->assertTrue($account->fresh()->subscription->trashed());
    }

    public function test_a_router_asset_or_subscription_in_use_cannot_be_deleted_from_the_database(): void
    {
        $account = $this->account();
        $nas = $this->nasDevice();

        $this->assertQueryFails(fn () => DB::table('network_assets')->where('id', $nas->network_asset_id)->delete());
        $this->assertQueryFails(fn () => DB::table('service_subscriptions')->where('id', $account->subscription_id)->delete());
    }

    public function test_the_factories_use_documentation_range_addresses_and_fake_credentials(): void
    {
        $nas = $this->nasDevice();

        $this->assertMatchesRegularExpression('/^192\.0\.2\.\d+$/', $nas->nasname);
        $this->assertSame('router', $nas->networkAsset->asset_type);
        $this->assertSame('fake-radius-shared-secret', $nas->radius_secret);
        $this->assertSame(1, NetworkAssetFactory::new()->count(1)->make()->count());
    }

    private function assertQueryFails(callable $statement): void
    {
        try {
            $statement();
        } catch (QueryException) {
            $this->addToAssertionCount(1);

            return;
        }

        $this->fail('The database accepted a row it must refuse.');
    }
}
