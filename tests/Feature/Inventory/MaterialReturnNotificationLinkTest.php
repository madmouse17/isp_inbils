<?php

namespace Tests\Feature\Inventory;

use App\Models\Core\Company;
use App\Models\Core\Location;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Notifications\MaterialReturnDeclaredNotification;
use Modules\Inventory\Notifications\MaterialReturnExceptionNotification;
use Modules\Inventory\Notifications\MaterialReturnRejectedNotification;
use Tests\TestCase;

/** A return mail must open a page: `admin.inventory.returns.show` answers JSON, so the mails point at the request or the returns tab. */
class MaterialReturnNotificationLinkTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $company = Company::factory()->create(['is_active' => true]);
        $this->admin = User::factory()->create(['company_id' => $company->id, 'email_verified_at' => now(), 'is_active' => true]);
        $this->admin->assignRole('admin');
        $this->actingAs($this->admin);
    }

    public function test_return_mails_open_the_request_page_that_shows_the_return(): void
    {
        $requestId = $this->requestId();

        foreach ($this->mails($requestId) as $mail) {
            $url = $mail->toMail($this->admin)->actionUrl;

            $this->assertSame(route('admin.inventory.requests.show', $requestId), $url, $mail::class);
            $this->get($url)->assertOk()
                ->assertInertia(fn (Assert $page) => $page->component('Admin/Inventory/Requests/Show'));
        }
    }

    public function test_a_case_without_a_request_opens_the_returns_tab(): void
    {
        foreach ($this->mails(null) as $mail) {
            $url = $mail->toMail($this->admin)->actionUrl;

            $this->assertSame(route('admin.inventory.requests.index', ['tab' => 'returns']), $url, $mail::class);
            $this->get($url)->assertOk()
                ->assertInertia(fn (Assert $page) => $page->component('Admin/Inventory/Requests/Index'));
        }
    }

    public function test_the_stored_payload_keeps_the_request_id(): void
    {
        $payload = (new MaterialReturnDeclaredNotification(7, null, 1, 42))->toArray($this->admin);

        $this->assertSame(42, $payload['inventory_request_id']);
    }

    /** @return list<MaterialReturnDeclaredNotification|MaterialReturnExceptionNotification|MaterialReturnRejectedNotification> */
    private function mails(?int $requestId): array
    {
        return [
            new MaterialReturnDeclaredNotification(7, null, $this->admin->company_id, $requestId),
            new MaterialReturnExceptionNotification(7, null, $this->admin->company_id, $requestId),
            new MaterialReturnRejectedNotification(7, 'Barang belum diserahkan', $this->admin->company_id, $requestId),
        ];
    }

    private function requestId(): int
    {
        $location = Location::factory()->create(['company_id' => $this->admin->company_id]);
        $product = Product::factory()->create(['company_id' => $this->admin->company_id]);

        return $this->postJson(route('admin.inventory.requests.store'), [
            'warehouse_location_id' => $location->id,
            'reason' => 'Kebutuhan penarikan kabel pelanggan baru',
            'items' => [['product_id' => $product->id, 'quantity_requested' => 5]],
        ])->assertCreated()->json('data.id');
    }
}
