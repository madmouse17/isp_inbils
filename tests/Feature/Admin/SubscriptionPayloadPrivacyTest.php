<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\Core\Customer;
use App\Models\Core\ServiceSubscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\CreatesCompanyUser;

/** The subscription page names its customer; the customer's record stays on the customer pages. */
class SubscriptionPayloadPrivacyTest extends TestCase
{
    use CreatesCompanyUser;
    use RefreshDatabase;

    private const PRIVATE = ['sari.pribadi@example.test', '99.888.777.6-543.210', 'Pelanggan lama, minta dihubungi sore hari.', 'Bapak Rahmat', '081234567890'];

    public function test_show_embeds_a_customer_summary_only(): void
    {
        $this->withoutVite();
        $admin = $this->createCompanyUser();
        $customer = Customer::factory()->create([
            'company_id' => $admin->company_id, 'name' => 'Sari Wulandari', 'phone' => self::PRIVATE[4], 'email' => self::PRIVATE[0],
            'tax_id' => self::PRIVATE[1], 'notes' => self::PRIVATE[2], 'contact_person' => self::PRIVATE[3],
        ]);
        $subscription = ServiceSubscription::factory()->create(['company_id' => $admin->company_id, 'customer_id' => $customer->id]);

        $props = $this->actingAs($admin)->get(route('admin.subscriptions.show', $subscription))->assertOk()->viewData('page')['props'];

        $this->assertSame(['id' => $customer->public_id, 'code' => $customer->code, 'name' => 'Sari Wulandari'], $props['subscription']['data']['customer']);
        $json = (string) json_encode($props['subscription']);
        foreach (self::PRIVATE as $private) {
            $this->assertStringNotContainsString($private, $json, $private);
        }
    }
}
