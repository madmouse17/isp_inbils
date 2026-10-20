<?php

namespace Tests\Feature\Api\V1\Customer;

use App\Models\Core\Customer;
use App\Models\Core\CustomerAddress;
use App\Models\Core\ServiceSubscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\NetworkAsset\Models\NetworkAccount;
use Modules\Service\Database\Factories\ServicePackageFactory;
use Modules\Service\Models\BandwidthProfile;
use Modules\Service\Models\ServicePackage;
use Modules\Service\Models\ServicePackageCategory;
use Modules\Service\Models\SLATier;
use Modules\Service\Models\SpeedProfile;
use Tests\TestCase;
use Tests\Traits\InteractsWithMobileApi;

class ProfileAndSubscriptionsTest extends TestCase
{
    use InteractsWithMobileApi;
    use RefreshDatabase;

    /** @var list<string> */
    private const FORBIDDEN_KEYS = [
        'static_ip', 'assigned_ip', 'ip_address', 'blocked_at', 'terminated_reason', 'otc_installation_fee',
        'tax_id', 'notes', 'area_coverage_id', 'ont_asset_id', 'serving_pop_id',
        'whatsapp', 'whatsapp_opt_in_at', 'whatsapp_opt_out_at', 'network_account',
        'network_accounts', 'username', 'password',
    ];

    public function test_customer_profile_is_read_only_allow_list(): void
    {
        [$user, $customer] = $this->customerLogin();
        $customer->forceFill([
            'type' => 'Company',
            'contact_person' => 'Kontak Utama',
            'customer_status' => 'active',
            'tax_id' => '12.345.678.9-012.345',
            'notes' => 'internal note',
        ])->save();
        $address = CustomerAddress::factory()->create([
            'company_id' => $customer->company_id,
            'customer_id' => $customer->id,
            'address' => 'Jl. Utama 1',
            'city' => 'Jakarta',
            'postal_code' => '12345',
            'is_primary' => true,
            'is_installation_point' => false,
        ]);

        $data = $this->api('GET', 'customer/profile', token: $this->issueToken($user, 'customer'))
            ->assertOk()
            ->json('data');

        $this->assertSame(['code', 'name', 'type', 'email', 'phone', 'contact_person', 'status', 'addresses'], array_keys($data));
        $this->assertSame(['id', 'address', 'city', 'postal_code', 'is_primary', 'is_installation_point'], array_keys($data['addresses'][0]));
        $this->assertSame($address->public_id, $data['addresses'][0]['id']);
        $this->assertSame('active', $data['status']);
        $this->assertNoForbiddenKeys($data);
    }

    public function test_subscriptions_are_customer_scoped_paginated_and_allow_listed(): void
    {
        [$user, $customer] = $this->customerLogin();
        [, $otherCustomer] = $this->customerLogin();
        $subscription = $this->subscriptionFor($customer);
        $foreign = $this->subscriptionFor($otherCustomer);

        $list = $this->api('GET', 'customer/subscriptions?per_page=50', token: $this->issueToken($user, 'customer'))
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->json('data.0');

        $this->assertSame([
            'id', 'code', 'status', 'suspension_reason', 'suspended_at', 'reactivation_holds', 'package', 'sla_tier', 'pppoe_username', 'mrc_amount', 'installation_address',
            'activation_date', 'billing_day', 'next_invoice_date', 'contract_months', 'expiration_date',
        ], array_keys($list));
        $this->assertSame(['id', 'name', 'category', 'download_mbps', 'upload_mbps', 'speed_label'], array_keys($list['package']));
        $this->assertSame(['download_mbps' => 20, 'upload_mbps' => 10, 'speed_label' => 'Hingga 50 Mbps'], array_intersect_key($list['package'], array_flip(['download_mbps', 'upload_mbps', 'speed_label'])));
        $this->assertSame($subscription->public_id, $list['id']);
        $this->assertSame(ServicePackage::withoutCompany()->whereKey($subscription->service_package_id)->value('public_id'), $list['package']['id']);
        $this->assertSame(['id' => $this->bisnisCategory($customer)->public_id, 'name' => 'Bisnis'], $list['package']['category']);
        $this->assertSame(['address', 'city', 'label', 'lat', 'lng'], array_keys($list['installation_address']));
        $this->assertSame([], $list['reactivation_holds'], 'an active subscription is held by nothing');
        $this->assertNoForbiddenKeys($list);

        $this->api('GET', 'customer/subscriptions/'.$subscription->public_id, token: $this->issueToken($user, 'customer'))
            ->assertOk()
            ->assertJsonPath('data.id', $subscription->public_id);
        $this->api('GET', 'customer/subscriptions/'.$foreign->public_id, token: $this->issueToken($user, 'customer'))
            ->assertNotFound();
    }

    public function test_subscription_carries_sla_tier_pppoe_username_address_coordinates_and_suspended_at(): void
    {
        [$user, $customer] = $this->customerLogin();
        $token = $this->issueToken($user, 'customer');
        $subscription = $this->subscriptionFor($customer, ['status' => 'suspended', 'suspension_source' => 'manual', 'suspended_at' => '2026-10-01 08:00:00']);
        $package = ServicePackage::withoutCompany()->findOrFail($subscription->service_package_id);
        SLATier::withoutCompany()->whereKey($package->sla_tier_id)->update(['name' => 'Gold SLA', 'response_time_hours' => 4, 'resolution_time_hours' => 24]);
        NetworkAccount::factory()->create(['company_id' => $customer->company_id, 'subscription_id' => $subscription->id, 'username' => 'budi-01@isp', 'password' => 'topsecret-pppoe']);
        CustomerAddress::withoutCompany()->whereKey($subscription->installation_address_id)->update(['label' => 'Rumah Utama', 'lat' => -6.2000000, 'lng' => 106.8166667]);

        $body = $this->api('GET', 'customer/subscriptions/'.$subscription->public_id, token: $token)->assertOk();

        $body->assertJsonPath('data.sla_tier', ['name' => 'Gold SLA', 'response_time_hours' => 4, 'resolution_time_hours' => 24])
            ->assertJsonPath('data.pppoe_username', 'budi-01@isp')
            ->assertJsonPath('data.installation_address.label', 'Rumah Utama')
            ->assertJsonPath('data.installation_address.lat', '-6.2000000')
            ->assertJsonPath('data.installation_address.lng', '106.8166667')
            ->assertJsonPath('data.suspended_at', '2026-10-01T08:00:00+00:00');
        $this->assertStringNotContainsString('topsecret-pppoe', $body->getContent());
        $this->assertNoForbiddenKeys($body->json('data'));
    }

    public function test_new_subscription_fields_are_null_without_an_account_or_suspension_and_load_in_one_query_each(): void
    {
        [$user, $customer] = $this->customerLogin();
        $token = $this->issueToken($user, 'customer');
        $hotspotSubscription = $this->subscriptionFor($customer);
        foreach (range(1, 2) as $ignored) {
            $this->subscriptionFor($customer);
        }
        NetworkAccount::factory()->hotspot()->create(['company_id' => $customer->company_id, 'subscription_id' => $hotspotSubscription->id, 'username' => 'hotspot-user@x']);

        DB::enableQueryLog();
        $rows = $this->api('GET', 'customer/subscriptions?per_page=50', token: $token)->assertOk()->json('data');
        $log = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertSame([null, null, null], array_column($rows, 'pppoe_username'), 'a hotspot login is not a PPPoE username');
        $this->assertSame([null, null, null], array_column($rows, 'suspended_at'));
        foreach (['network_accounts', 'sla_tiers'] as $table) {
            $this->assertCount(1, array_filter($log, fn (array $q): bool => str_contains($q['query'], '`'.$table.'`')), "one eager load of {$table}");
        }
    }

    public function test_suspension_reason_names_how_a_suspended_subscription_was_put_on_hold_and_is_null_otherwise(): void
    {
        [$user, $customer] = $this->customerLogin();
        $token = $this->issueToken($user, 'customer');
        $cases = [
            'overdue' => $this->subscriptionFor($customer, ['status' => 'suspended', 'suspension_source' => 'overdue']),
            'manual' => $this->subscriptionFor($customer, ['status' => 'suspended', 'suspension_source' => 'manual']),
            'legacy suspended row without a source' => $this->subscriptionFor($customer, ['status' => 'suspended', 'suspension_source' => null]),
            'active' => $this->subscriptionFor($customer, ['status' => 'active', 'suspension_source' => null]),
            'active with a leftover source' => $this->subscriptionFor($customer, ['status' => 'active', 'suspension_source' => 'overdue']),
        ];
        $expected = ['overdue' => 'overdue', 'manual' => 'manual', 'legacy suspended row without a source' => null, 'active' => null, 'active with a leftover source' => null];

        $listed = collect($this->api('GET', 'customer/subscriptions?per_page=50', token: $token)->assertOk()->json('data'))->pluck('suspension_reason', 'id');

        foreach ($cases as $label => $subscription) {
            $this->assertSame($expected[$label], $listed[$subscription->public_id], "list: {$label}");
            $this->api('GET', 'customer/subscriptions/'.$subscription->public_id, token: $token)->assertOk()->assertJsonPath('data.suspension_reason', $expected[$label]);
        }
    }

    public function test_package_category_is_loaded_without_a_query_per_subscription(): void
    {
        [$user, $customer] = $this->customerLogin();
        $token = $this->issueToken($user, 'customer');
        foreach (range(1, 3) as $ignored) {
            $this->subscriptionFor($customer);
        }

        DB::enableQueryLog();
        $response = $this->api('GET', 'customer/subscriptions?per_page=50', token: $token)->assertOk()->assertJsonCount(3, 'data');
        $categoryQueries = array_filter(DB::getQueryLog(), fn (array $query): bool => str_contains($query['query'], 'service_package_categories'));
        DB::disableQueryLog();

        $this->assertCount(1, $categoryQueries, 'one eager load for every subscription on the page');
        $this->assertSame(['Bisnis'], array_values(array_unique($response->json('data.*.package.category.name'))));
    }

    public function test_package_speed_fields_keep_the_values_of_soft_deleted_profiles(): void
    {
        [$user, $customer] = $this->customerLogin();
        $token = $this->issueToken($user, 'customer');
        $bothDeleted = $this->subscriptionFor($customer);
        $this->dropProfiles($bothDeleted, bandwidth: true, speed: true);
        $speedDeleted = $this->subscriptionFor($customer);
        $this->dropProfiles($speedDeleted, speed: true);

        // A deleted profile is history, not a hole (master-lifecycle D6): the subscription keeps the speed it was sold with.
        foreach ([$bothDeleted, $speedDeleted] as $subscription) {
            $this->api('GET', 'customer/subscriptions/'.$subscription->public_id, token: $token)->assertOk()
                ->assertJsonPath('data.package.download_mbps', 20)
                ->assertJsonPath('data.package.upload_mbps', 10)
                ->assertJsonPath('data.package.speed_label', 'Hingga 50 Mbps');
        }
    }

    public function test_customer_routes_reject_technician_tokens(): void
    {
        $technician = $this->technicianLogin();

        $this->api('GET', 'customer/profile', token: $this->issueToken($technician, 'technician'))
            ->assertForbidden()
            ->assertJsonPath('code', 'forbidden');
    }

    private function bisnisCategory(Customer $customer): ServicePackageCategory
    {
        return ServicePackageCategory::withoutCompany()->findOrFail(ServicePackageCategory::ensureDefaults((int) $customer->company_id)['BISNIS']);
    }

    private function dropProfiles(ServiceSubscription $subscription, bool $bandwidth = false, bool $speed = false): void
    {
        $package = ServicePackage::withoutCompany()->findOrFail($subscription->service_package_id);
        $bandwidth && BandwidthProfile::withoutCompany()->whereKey($package->bandwidth_profile_id)->delete();
        $speed && SpeedProfile::withoutCompany()->whereKey($package->speed_profile_id)->delete();
    }

    /** @param array<string, mixed> $overrides */
    private function subscriptionFor(Customer $customer, array $overrides = []): ServiceSubscription
    {
        $address = CustomerAddress::factory()->create([
            'company_id' => $customer->company_id,
            'customer_id' => $customer->id,
            'address' => 'Jl. Pasang 2',
            'label' => uniqid('Rumah '),
            'city' => 'Jakarta',
        ]);
        $package = ServicePackageFactory::new()->create([
            'company_id' => $customer->company_id,
            'bandwidth_profile_id' => BandwidthProfile::factory()->create(['company_id' => $customer->company_id, 'name' => uniqid('BW '), 'download_mbps' => 20, 'upload_mbps' => 10])->id,
            'speed_profile_id' => SpeedProfile::factory()->create(['company_id' => $customer->company_id, 'name' => uniqid('SP '), 'download_max_mbps' => 50, 'upload_max_mbps' => 25])->id,
            'name' => 'Fiber 20 Mbps',
            'service_package_category_id' => ServicePackageCategory::ensureDefaults((int) $customer->company_id)['BISNIS'],
        ]);

        return ServiceSubscription::factory()->create([
            'company_id' => $customer->company_id,
            'customer_id' => $customer->id,
            'service_package_id' => $package->id,
            'installation_address_id' => $address->id,
            'status' => 'active',
            'activation_date' => '2026-01-10',
            'billing_day' => 10,
            'next_invoice_date' => '2026-11-10',
            'contract_months' => 12,
            'expiration_date' => '2027-01-09',
            'mrc_amount' => 250000,
            'serving_pop_id' => null,
            'notes' => 'private note',
            ...$overrides,
        ]);
    }

    /** @param array<string, mixed> $value */
    private function assertNoForbiddenKeys(array $value): void
    {
        foreach ($value as $key => $nested) {
            $this->assertNotContains($key, self::FORBIDDEN_KEYS, 'Forbidden key leaked: '.$key);

            if (is_array($nested)) {
                $this->assertNoForbiddenKeys($nested);
            }
        }
    }
}
