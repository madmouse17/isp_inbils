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
            'nas_device_id' => $this->nasDevice($company)->id,
        ]);
    }

    public function test_both_passwords_are_stored_encrypted_and_read_back_in_clear(): void
    {
        $nas = NasDevice::factory()->create(['company_id' => $this->company->id, 'api_password' => 'fake-api-plain-9']);
        $account = $this->account(attributes: ['password' => 'fake-subscriber-plain-9']);

        $rawNas = DB::table('nas_devices')->where('id', $nas->id)->value('api_password');
        $rawAccount = DB::table('network_accounts')->where('id', $account->id)->value('password');

        $this->assertNotSame('fake-api-plain-9', $rawNas);
        $this->assertStringNotContainsString('fake-api-plain-9', $rawNas);
        $this->assertNotSame('fake-subscriber-plain-9', $rawAccount);
        $this->assertStringNotContainsString('fake-subscriber-plain-9', $rawAccount);
        $this->assertSame('fake-api-plain-9', $nas->fresh()->api_password);
        $this->assertSame('fake-subscriber-plain-9', $account->fresh()->password);
    }

    public function test_neither_password_appears_in_arrays_or_json(): void
    {
        $nas = NasDevice::factory()->create(['company_id' => $this->company->id, 'api_password' => 'fake-api-plain-9']);
        $account = $this->account(attributes: ['password' => 'fake-subscriber-plain-9', 'nas_device_id' => $nas->id]);
        $account->load(['nasDevice', 'subscription']);

        foreach ([$nas->toArray(), $account->toArray(), json_decode($nas->toJson(), true), json_decode($account->toJson(), true), json_decode(json_encode(collect([$nas, $account])), true)] as $serialised) {
            $encoded = json_encode($serialised);
            $this->assertStringNotContainsString('fake-api-plain-9', $encoded);
            $this->assertStringNotContainsString('fake-subscriber-plain-9', $encoded);
        }
        $this->assertArrayNotHasKey('api_password', $nas->toArray());
        $this->assertArrayNotHasKey('password', $account->toArray());
        $this->assertArrayNotHasKey('api_password', $account->toArray()['nas_device']);
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
            fn () => NasDevice::query()->create(['network_asset_id' => $nas->network_asset_id, 'api_username' => 'x', 'api_password' => 'y']),
            fn () => NetworkAccount::query()->create(['subscription_id' => 1, 'nas_device_id' => $nas->id, 'username' => 'abc', 'password' => 'secret1']),
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

    public function test_usernames_are_unique_per_company_and_the_same_name_is_fine_in_another_company(): void
    {
        $first = $this->account(attributes: ['username' => 'alice']);

        $this->assertQueryFails(fn () => $this->account(attributes: ['username' => 'alice']));

        $other = Company::factory()->create(['is_active' => true]);
        $elsewhere = $this->account($other, ['username' => 'alice']);
        $this->assertSame('alice', $elsewhere->username);
        $this->assertNotSame($first->company_id, $elsewhere->company_id);
    }

    public function test_one_account_per_subscription_and_one_nas_device_per_router_asset(): void
    {
        $account = $this->account();

        $this->assertQueryFails(fn () => NetworkAccount::factory()->create([
            'company_id' => $this->company->id,
            'subscription_id' => $account->subscription_id,
            'nas_device_id' => $account->nas_device_id,
        ]));
        $this->assertQueryFails(fn () => NasDevice::factory()->create([
            'company_id' => $this->company->id,
            'network_asset_id' => $account->nasDevice->network_asset_id,
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

        $account->update([
            'sync_status' => 'failed',
            'provisioned_profile' => 'home-20m',
            'provisioned_username' => 'alice',
            'provisioned_nas_device_id' => $account->nas_device_id,
            'last_error' => 'x',
            'company_id' => 999,
        ]);
        $nas = $account->nasDevice;
        $nas->update(['unreachable_since' => now(), 'last_error' => 'x', 'routeros_version' => '7', 'unmanaged_count' => 5]);

        $account = $account->fresh();
        $this->assertSame('pending', $account->sync_status);
        $this->assertNull($account->provisioned_profile);
        $this->assertNull($account->provisioned_username);
        $this->assertNull($account->last_error);
        $this->assertSame($this->company->id, $account->company_id);
        $nas = $nas->fresh();
        $this->assertNull($nas->unreachable_since);
        $this->assertNull($nas->last_error);
        $this->assertSame(0, $nas->unmanaged_count);
    }

    public function test_engine_columns_are_written_with_force_fill_and_cast(): void
    {
        $nas = $this->nasDevice();

        $nas->forceFill(['unmanaged_usernames' => ['stray-1', 'stray-2'], 'unreachable_since' => '2026-10-15 08:00:00', 'provisioning_enabled' => 1])->save();

        $nas = $nas->fresh();
        $this->assertSame(['stray-1', 'stray-2'], $nas->unmanaged_usernames);
        $this->assertSame('2026-10-15 08:00:00', $nas->unreachable_since->format('Y-m-d H:i:s'));
        $this->assertTrue($nas->provisioning_enabled);
        $this->assertSame(443, $nas->api_port);
        $this->assertSame('isolir', $nas->isolir_profile);
    }

    public function test_defaults_and_relations(): void
    {
        $account = $this->account();

        $this->assertSame('pppoe', $account->fresh()->service_type);
        $this->assertSame('pending', $account->fresh()->sync_status);
        $this->assertFalse($account->nasDevice->provisioning_enabled);
        $this->assertSame($account->nas_device_id, $account->nasDevice->networkAccounts()->first()->nas_device_id);
        $this->assertSame('router', $account->nasDevice->networkAsset->asset_type);
        $this->assertNull($account->provisionedNasDevice);

        $account->forceFill(['provisioned_nas_device_id' => $account->nas_device_id])->save();
        $this->assertTrue($account->fresh()->provisionedNasDevice->is($account->nasDevice));
    }

    public function test_the_subscription_resolves_even_after_it_is_soft_deleted(): void
    {
        $account = $this->account();
        ServiceSubscription::query()->whereKey($account->subscription_id)->firstOrFail()->delete();

        $this->assertTrue($account->fresh()->subscription->trashed());
    }

    public function test_a_router_asset_or_nas_device_in_use_cannot_be_deleted_from_the_database(): void
    {
        $account = $this->account();

        $this->assertQueryFails(fn () => DB::table('nas_devices')->where('id', $account->nas_device_id)->delete());
        $this->assertQueryFails(fn () => DB::table('network_assets')->where('id', $account->nasDevice->network_asset_id)->delete());
        $this->assertQueryFails(fn () => DB::table('service_subscriptions')->where('id', $account->subscription_id)->delete());
    }

    public function test_the_factories_use_documentation_range_addresses_and_fake_credentials(): void
    {
        $nas = $this->nasDevice();

        $this->assertMatchesRegularExpression('/^192\.0\.2\.\d+$/', $nas->networkAsset->management_ip);
        $this->assertSame('router', $nas->networkAsset->asset_type);
        $this->assertSame('fake-router-api-password', $nas->api_password);
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
