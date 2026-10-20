<?php

namespace Tests\Feature\Inventory;

use App\Models\Core\Company;
use App\Models\Core\Location;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Inventory\Models\InventoryRequest;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Notifications\MaterialReturnDeclaredNotification;
use Modules\Inventory\Notifications\MaterialReturnExceptionNotification;
use Modules\Inventory\Notifications\MaterialReturnRejectedNotification;
use Tests\TestCase;

/**
 * A return has no page of its own (`admin.inventory.returns.show` answers JSON): its bell row names the request it
 * belongs to by public id, and the request page shows the return. An integer id never reaches the row.
 */
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

    public function test_the_stored_payload_keeps_the_request_id(): void
    {
        $request = $this->inventoryRequest();

        foreach ($this->notices($request->id) as $notice) {
            $payload = $notice->toArray($this->admin);

            $this->assertSame($request->public_id, $payload['inventory_request_id'], $notice::class);
            $this->assertSame($this->admin->company_id, $payload['company_id'], $notice::class);
        }
    }

    public function test_a_case_without_a_request_names_none(): void
    {
        foreach ($this->notices(null) as $notice) {
            $this->assertNull($notice->toArray($this->admin)['inventory_request_id'], $notice::class);
        }
    }

    public function test_the_rejection_carries_its_reason(): void
    {
        $payload = (new MaterialReturnRejectedNotification(7, 'Barang belum diserahkan', $this->admin->company_id, null))->toArray($this->admin);

        $this->assertSame(['material_return_rejected', 'Barang belum diserahkan'], [$payload['type'], $payload['reason']]);
    }

    /** @return list<MaterialReturnDeclaredNotification|MaterialReturnExceptionNotification|MaterialReturnRejectedNotification> */
    private function notices(?int $requestId): array
    {
        return [
            new MaterialReturnDeclaredNotification(7, null, $this->admin->company_id, $requestId),
            new MaterialReturnExceptionNotification(7, null, $this->admin->company_id, $requestId),
            new MaterialReturnRejectedNotification(7, 'Barang belum diserahkan', $this->admin->company_id, $requestId),
        ];
    }

    private function inventoryRequest(): InventoryRequest
    {
        $location = Location::factory()->create(['company_id' => $this->admin->company_id]);
        $product = Product::factory()->create(['company_id' => $this->admin->company_id]);

        $publicId = $this->postJson(route('admin.inventory.requests.store'), [
            'warehouse_location_id' => $location->public_id,
            'reason' => 'Kebutuhan penarikan kabel pelanggan baru',
            'items' => [['product_id' => $product->public_id, 'quantity_requested' => 5]],
        ])->assertCreated()->json('data.id');

        return InventoryRequest::wherePublicId($publicId)->firstOrFail();
    }
}
