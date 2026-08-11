<?php

namespace Tests\Feature\Inventory;

use App\Models\Core\Company;
use App\Models\Core\Location;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Inventory\Models\Category;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\StockMovement;
use Modules\Inventory\Models\Unit;
use Modules\Inventory\Services\StockService;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class StockRbacAuditTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_staff_can_receive_and_issue_but_not_transfer_or_adjust(): void
    {
        $staff = $this->userWithRole('staff');

        $this->actingAs($staff)
            ->post(route('admin.stocks.receive'), $this->payload())
            ->assertRedirect();

        StockService::receive($this->product->id, $this->locationA->id, 10);

        $this->actingAs($staff)
            ->post(route('admin.stocks.issue'), $this->payload(['quantity' => 1]))
            ->assertRedirect();

        $this->actingAs($staff)
            ->post(route('admin.stocks.transfer'), [
                'product_id' => $this->product->id,
                'from_location_id' => $this->locationA->id,
                'to_location_id' => $this->locationB->id,
                'quantity' => 1,
            ])
            ->assertForbidden();

        $this->actingAs($staff)
            ->post(route('admin.stocks.adjust'), [
                'product_id' => $this->product->id,
                'location_id' => $this->locationA->id,
                'new_quantity' => 5,
                'note' => 'cycle count',
            ])
            ->assertForbidden();
    }

    public function test_technician_can_issue_but_not_receive_transfer_or_adjust(): void
    {
        $tech = $this->userWithRole('technician');
        StockService::receive($this->product->id, $this->locationA->id, 5);

        $this->actingAs($tech)
            ->post(route('admin.stocks.issue'), $this->payload(['quantity' => 1]))
            ->assertRedirect();

        foreach ([
            route('admin.stocks.receive') => $this->payload(),
            route('admin.stocks.transfer') => [
                'product_id' => $this->product->id,
                'from_location_id' => $this->locationA->id,
                'to_location_id' => $this->locationB->id,
                'quantity' => 1,
            ],
            route('admin.stocks.adjust') => [
                'product_id' => $this->product->id,
                'location_id' => $this->locationA->id,
                'new_quantity' => 1,
                'note' => 'n',
            ],
        ] as $url => $body) {
            $this->actingAs($tech)->post($url, $body)->assertForbidden();
        }
    }

    public function test_manager_can_adjust(): void
    {
        $manager = $this->userWithRole('manager');
        StockService::receive($this->product->id, $this->locationA->id, 5);

        $this->actingAs($manager)
            ->post(route('admin.stocks.adjust'), [
                'product_id' => $this->product->id,
                'location_id' => $this->locationA->id,
                'new_quantity' => 3,
                'note' => 'cycle count',
            ])
            ->assertRedirect();
    }

    public function test_user_without_stock_permissions_is_denied_all_mutations(): void
    {
        // bare user: company tenant only, no role/permissions
        $user = User::factory()->create(['company_id' => $this->company->id]);

        foreach ([
            route('admin.stocks.receive') => $this->payload(),
            route('admin.stocks.issue') => $this->payload(),
            route('admin.stocks.transfer') => [
                'product_id' => $this->product->id,
                'from_location_id' => $this->locationA->id,
                'to_location_id' => $this->locationB->id,
                'quantity' => 1,
            ],
            route('admin.stocks.adjust') => [
                'product_id' => $this->product->id,
                'location_id' => $this->locationA->id,
                'new_quantity' => 1,
                'note' => 'n',
            ],
        ] as $url => $body) {
            $this->actingAs($user)->post($url, $body)->assertForbidden();
        }
    }

    public function test_manager_mutations_write_activity_audit_and_movement_actor(): void
    {
        $manager = $this->userWithRole('manager');

        $this->actingAs($manager)
            ->post(route('admin.stocks.receive'), $this->payload(['quantity' => 8]))
            ->assertRedirect();

        $receiveMovement = StockMovement::query()
            ->where('product_id', $this->product->id)
            ->where('movement_type', 'receive')
            ->latest('id')
            ->first();
        $this->assertNotNull($receiveMovement);
        $this->assertSame($manager->id, $receiveMovement->created_by);

        $receive = Activity::query()
            ->where('log_name', 'inventory')
            ->where('description', 'stock_received')
            ->latest('id')
            ->first();

        $this->assertNotNull($receive);
        $this->assertSame($manager->id, $receive->causer_id);
        $this->assertSame($this->product->id, $receive->properties['product_id'] ?? null);

        $this->actingAs($manager)
            ->post(route('admin.stocks.issue'), $this->payload(['quantity' => 2]))
            ->assertRedirect();

        $issueMovement = StockMovement::query()
            ->where('product_id', $this->product->id)
            ->where('movement_type', 'issue')
            ->latest('id')
            ->first();
        $this->assertNotNull($issueMovement);
        $this->assertSame($manager->id, $issueMovement->created_by);

        $this->assertTrue(
            Activity::query()
                ->where('log_name', 'inventory')
                ->where('description', 'stock_issued')
                ->where('causer_id', $manager->id)
                ->exists()
        );

        $this->actingAs($manager)
            ->post(route('admin.stocks.transfer'), [
                'product_id' => $this->product->id,
                'from_location_id' => $this->locationA->id,
                'to_location_id' => $this->locationB->id,
                'quantity' => 1,
                'note' => 'move',
            ])
            ->assertRedirect();

        $this->assertTrue(
            Activity::query()
                ->where('log_name', 'inventory')
                ->where('description', 'stock_transfer_out')
                ->where('causer_id', $manager->id)
                ->exists()
        );
        $this->assertTrue(
            Activity::query()
                ->where('log_name', 'inventory')
                ->where('description', 'stock_transfer_in')
                ->where('causer_id', $manager->id)
                ->exists()
        );

        $this->actingAs($manager)
            ->post(route('admin.stocks.adjust'), [
                'product_id' => $this->product->id,
                'location_id' => $this->locationA->id,
                'new_quantity' => 3,
                'note' => 'cycle count',
            ])
            ->assertRedirect();

        $adjustMovement = StockMovement::query()
            ->where('product_id', $this->product->id)
            ->where('movement_type', 'adjustment')
            ->latest('id')
            ->first();
        $this->assertNotNull($adjustMovement);
        $this->assertSame($manager->id, $adjustMovement->created_by);

        $adjust = Activity::query()
            ->where('log_name', 'inventory')
            ->where('description', 'stock_adjustment')
            ->where('causer_id', $manager->id)
            ->latest('id')
            ->first();

        $this->assertNotNull($adjust);
        $this->assertSame('cycle count', $adjust->properties['note'] ?? null);
        $props = $adjust->properties->toArray();
        $this->assertArrayHasKey('old_quantity', $props);
        $this->assertArrayHasKey('new_quantity', $props);
    }

    public function test_service_layer_receive_writes_audit_even_without_http(): void
    {
        $manager = $this->userWithRole('manager');
        $this->actingAs($manager);

        StockService::receive($this->product->id, $this->locationA->id, 4, 'po receive');

        $movement = StockMovement::query()
            ->where('product_id', $this->product->id)
            ->where('movement_type', 'receive')
            ->latest('id')
            ->first();
        $this->assertNotNull($movement);
        $this->assertSame($manager->id, $movement->created_by);

        $row = Activity::query()
            ->where('log_name', 'inventory')
            ->where('description', 'stock_received')
            ->latest('id')
            ->first();

        $this->assertNotNull($row);
        $this->assertSame($manager->id, $row->causer_id);
        $this->assertSame(4.0, (float) ($row->properties['quantity'] ?? 0));
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create(['company_id' => $this->company->id]);
        $user->assignRole(Role::findByName($role));

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

    /** @param  array<string, mixed>  $overrides */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'product_id' => $this->product->id,
            'location_id' => $this->locationA->id,
            'quantity' => 5,
            'note' => 'test',
        ], $overrides);
    }
}
