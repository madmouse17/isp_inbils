<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Models\Core\Company;
use App\Models\Core\Location;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Inventory\Models\Category;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\Unit;
use Modules\Inventory\Services\StockService;
use Tests\TestCase;
use Tests\Traits\WithoutAppOnlyRedirect;

/**
 * Bounded remediation verification: stock adjust/transfer are gated by
 * explicit permissions, while the receive flow stays unchanged.
 *
 * Aligned with the actual repository contract: routes admin.stocks.*,
 * permission slugs inventory.stock.*, location-based payloads.
 */
class StockPermissionTest extends TestCase
{
    use RefreshDatabase;
    use WithoutAppOnlyRedirect;

    private Company $company;

    private Product $product;

    private Location $locationA;

    private Location $locationB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        app()['cache']->forget('spatie.permission.cache');

        $this->company = Company::factory()->create();
        // BelongsToCompany scopes writes from Auth::user()->company_id
        $this->actingAs(User::factory()->create(['company_id' => $this->company->id]));

        $unit = Unit::query()->create(['name' => 'Piece', 'symbol' => 'PCS']);
        $category = Category::query()->create([
            'name' => 'CPE',
            'code' => 'CPE',
            'unit_id' => $unit->id,
        ]);
        $this->product = Product::query()->create([
            'category_id' => $category->id,
            'unit_id' => $unit->id,
            'sku' => 'CPE-001',
            'name' => 'ONT',
            'type' => 'consumable',
            'track_stock' => true,
            'min_stock' => 0,
            'is_active' => true,
        ]);
        $this->locationA = $this->makeLocation('WH-A');
        $this->locationB = $this->makeLocation('WH-B');
    }

    public function test_user_without_stock_permission_is_forbidden_from_adjust_and_transfer(): void
    {
        $user = User::factory()->create(['company_id' => $this->company->id]);

        foreach (['admin.stocks.adjust', 'admin.stocks.adjust.direct'] as $adjustRoute) {
            $this->actingAs($user)
                ->post(route($adjustRoute), $this->adjustPayload())
                ->assertForbidden();
        }

        $this->actingAs($user)
            ->post(route('admin.stocks.transfer'), $this->transferPayload())
            ->assertForbidden();

        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertDatabaseCount('stock_adjustment_requests', 0);
    }

    public function test_technician_is_forbidden_from_adjust_and_transfer(): void
    {
        $technician = $this->userWithRole('technician');

        foreach (['admin.stocks.adjust', 'admin.stocks.adjust.direct'] as $adjustRoute) {
            $this->actingAs($technician)
                ->post(route($adjustRoute), $this->adjustPayload())
                ->assertForbidden();
        }

        $this->actingAs($technician)
            ->post(route('admin.stocks.transfer'), $this->transferPayload())
            ->assertForbidden();

        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertDatabaseCount('stock_adjustment_requests', 0);
    }

    public function test_user_with_only_direct_adjust_permission_cannot_transfer_or_request(): void
    {
        $user = User::factory()->create(['company_id' => $this->company->id]);
        $user->givePermissionTo('inventory.stock.adjust');

        $this->actingAs($user)
            ->post(route('admin.stocks.transfer'), $this->transferPayload())
            ->assertForbidden();

        // The request route needs inventory.stock.adjust.request, not the break-glass permission.
        $this->actingAs($user)
            ->post(route('admin.stocks.adjust'), $this->adjustPayload(quantity: 3))
            ->assertForbidden();

        StockService::receive($this->product->id, $this->locationA->id, 5);

        $this->actingAs($user)
            ->post(route('admin.stocks.adjust.direct'), $this->adjustPayload(quantity: 3))
            ->assertRedirect();

        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $this->product->id,
            'movement_type' => 'adjustment',
        ]);
    }

    public function test_admin_can_adjust_and_transfer(): void
    {
        $admin = $this->userWithRole('admin');
        StockService::receive($this->product->id, $this->locationA->id, 10);

        $this->actingAs($admin)
            ->post(route('admin.stocks.adjust.direct'), $this->adjustPayload(quantity: 5))
            ->assertRedirect();

        $this->actingAs($admin)
            ->post(route('admin.stocks.transfer'), $this->transferPayload())
            ->assertRedirect();

        // 1 seed receive + 1 adjustment + 2 legs of transfer
        $this->assertDatabaseCount('stock_movements', 4);
        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $this->product->id,
            'movement_type' => 'adjustment',
        ]);
        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $this->product->id,
            'movement_type' => 'transfer',
            'quantity' => -1,
        ]);
        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $this->product->id,
            'movement_type' => 'transfer',
            'quantity' => 1,
        ]);
    }

    public function test_receive_flow_remains_available_to_staff_role(): void
    {
        $staff = $this->userWithRole('staff');

        $this->actingAs($staff)
            ->post(route('admin.stocks.receive'), $this->receivePayload())
            ->assertRedirect();

        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $this->product->id,
            'movement_type' => 'receive',
        ]);
    }

    public function test_user_without_receive_permission_cannot_receive(): void
    {
        $user = User::factory()->create(['company_id' => $this->company->id]);

        $this->actingAs($user)
            ->post(route('admin.stocks.receive'), $this->receivePayload())
            ->assertForbidden();

        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_technician_index_payload_has_receive_capability_disabled(): void
    {
        $technician = $this->userWithRole('technician');

        $this->actingAs($technician)
            ->get(route('admin.stocks.index'))
            ->assertOk()
            ->assertInertia(
                fn (Assert $page) => $page
                    ->component('Admin/Inventory/Stocks/Index')
                    ->where('can.receive', false)
            );
    }

    public function test_staff_index_payload_has_receive_capability_enabled(): void
    {
        $staff = $this->userWithRole('staff');

        $this->actingAs($staff)
            ->get(route('admin.stocks.index'))
            ->assertOk()
            ->assertInertia(
                fn (Assert $page) => $page
                    ->component('Admin/Inventory/Stocks/Index')
                    ->where('can.receive', true)
            );
    }

    public function test_technician_index_page_source_hides_receive_action(): void
    {
        $technician = $this->userWithRole('technician');

        $response = $this->actingAs($technician)->get(route('admin.stocks.index'));

        $response->assertOk();
        // Inertia embeds the page props as a JSON data-page attribute; the
        // receive capability must be serialized as false for technicians.
        $this->assertStringContainsString('&quot;receive&quot;:false', $response->getContent());
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create(['company_id' => $this->company->id]);
        $user->assignRole($role);

        return $user;
    }

    private function makeLocation(string $code): Location
    {
        return Location::query()->create([
            'name' => $code,
            'code' => $code,
            'type' => 'site',
            'is_active' => true,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function adjustPayload(int $quantity = 1): array
    {
        return [
            'product_id' => $this->product->public_id,
            'location_id' => $this->locationA->public_id,
            'new_quantity' => $quantity,
            'note' => 'permission check',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function transferPayload(): array
    {
        return [
            'product_id' => $this->product->public_id,
            'from_location_id' => $this->locationA->public_id,
            'to_location_id' => $this->locationB->public_id,
            'quantity' => 1,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function receivePayload(): array
    {
        return [
            'product_id' => $this->product->public_id,
            'location_id' => $this->locationA->public_id,
            'quantity' => 3,
            'note' => 'PO-PERM-001',
        ];
    }
}
