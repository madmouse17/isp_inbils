<?php

namespace Tests\Feature\Api\V1\Customer;

use App\Models\Core\Customer;
use App\Models\Core\CustomerAddress;
use App\Models\Core\ServiceSubscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Service\Database\Factories\ServicePackageFactory;
use Modules\Service\Models\ServicePackageCategory;
use Tests\TestCase;
use Tests\Traits\InteractsWithMobileApi;

class ProfileAndSubscriptionsTest extends TestCase
{
    use InteractsWithMobileApi;
    use RefreshDatabase;

    /** @var list<string> */
    private const FORBIDDEN_KEYS = [
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
        CustomerAddress::factory()->create([
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
            'id', 'code', 'status', 'suspension_reason', 'package', 'mrc_amount', 'installation_address', 'activation_date',
            'billing_day', 'next_invoice_date', 'contract_months', 'expiration_date',
        ], array_keys($list));
        $this->assertSame(['id', 'name', 'category'], array_keys($list['package']));
        $this->assertSame(['id' => ServicePackageCategory::ensureDefaults((int) $customer->company_id)['BISNIS'], 'name' => 'Bisnis'], $list['package']['category']);
        $this->assertSame(['address', 'city'], array_keys($list['installation_address']));
        $this->assertNoForbiddenKeys($list);

        $this->api('GET', 'customer/subscriptions/'.$subscription->id, token: $this->issueToken($user, 'customer'))
            ->assertOk()
            ->assertJsonPath('data.id', $subscription->id);
        $this->api('GET', 'customer/subscriptions/'.$foreign->id, token: $this->issueToken($user, 'customer'))
            ->assertNotFound();
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
            $this->assertSame($expected[$label], $listed[$subscription->id], "list: {$label}");
            $this->api('GET', 'customer/subscriptions/'.$subscription->id, token: $token)->assertOk()->assertJsonPath('data.suspension_reason', $expected[$label]);
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

    public function test_customer_routes_reject_technician_tokens(): void
    {
        $technician = $this->technicianLogin();

        $this->api('GET', 'customer/profile', token: $this->issueToken($technician, 'technician'))
            ->assertForbidden()
            ->assertJsonPath('code', 'forbidden');
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
