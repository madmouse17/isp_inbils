<?php

declare(strict_types=1);

namespace Tests\Feature\NetworkAsset;

use App\Models\Core\Customer;
use App\Models\Core\ServiceSubscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\NetworkAsset\Database\Factories\NetworkAssetFactory;
use Modules\NetworkAsset\Models\NetworkAsset;
use Tests\TestCase;
use Tests\Traits\CreatesCompanyUser;

/** The asset pages name the customer and the subscription an asset serves; the customer's record stays on the customer pages. */
class NetworkAssetPayloadPrivacyTest extends TestCase
{
    use CreatesCompanyUser;
    use RefreshDatabase;

    private const PRIVATE = ['sari.pribadi@example.test', '99.888.777.6-543.210', 'Pelanggan lama, minta dihubungi sore hari.', 'Bapak Rahmat', '081234567890', 'Catatan langganan rahasia'];

    private User $admin;

    private NetworkAsset $asset;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->admin = $this->createCompanyUser();
        $companyId = (int) $this->admin->company_id;
        $customer = Customer::factory()->create([
            'company_id' => $companyId, 'name' => 'Sari Wulandari', 'phone' => self::PRIVATE[4], 'email' => self::PRIVATE[0],
            'tax_id' => self::PRIVATE[1], 'notes' => self::PRIVATE[2], 'contact_person' => self::PRIVATE[3],
        ]);
        $subscription = ServiceSubscription::factory()->create(['company_id' => $companyId, 'customer_id' => $customer->id, 'notes' => self::PRIVATE[5]]);
        $this->asset = NetworkAssetFactory::new()->create([
            'company_id' => $companyId, 'customer_id' => $customer->id, 'subscription_id' => $subscription->id, 'status' => 'installed', 'notes' => null,
        ]);
    }

    public function test_index_and_show_embed_summaries_only(): void
    {
        $customer = Customer::query()->where('name', 'Sari Wulandari')->sole();
        $subscription = ServiceSubscription::query()->where('customer_id', Customer::query()->where('name', 'Sari Wulandari')->value('id'))->sole();

        foreach ([[route('admin.network-assets.index'), 'assets'], [route('admin.network-assets.show', $this->asset), 'asset']] as [$url, $prop]) {
            $props = $this->actingAs($this->admin)->get($url)->assertOk()->viewData('page')['props'];
            $row = $props[$prop]['data'][0] ?? $props[$prop]['data'];

            $this->assertSame(['id' => $customer->public_id, 'code' => $customer->code, 'name' => 'Sari Wulandari'], $row['customer']);
            $this->assertSame(['id' => $subscription->public_id, 'code' => $subscription->code], $row['subscription']);

            $json = (string) json_encode($props[$prop]);
            foreach (self::PRIVATE as $private) {
                $this->assertStringNotContainsString($private, $json, $private);
            }
        }
    }
}
