<?php

namespace Tests\Feature\Inventory;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Core\Company;
use App\Models\Core\Location;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Inventory\Models\Category;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\Stock;
use Modules\Inventory\Models\StockMovement;
use Modules\Inventory\Models\Unit;
use Modules\Inventory\Services\StockService;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;
use Tests\Traits\WithoutAppOnlyRedirect;

/** K30: a goods receipt keeps who supplied it, the invoice number and the unit price; the Stok page answers what a dialog shows. */
class StockReceiveDetailsTest extends TestCase
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
        $this->actingAs(User::factory()->create(['company_id' => $this->company->id]));

        $unit = Unit::query()->create(['name' => 'Piece', 'symbol' => 'PCS']);
        $category = Category::query()->create(['name' => 'CPE', 'code' => 'CPE', 'unit_id' => $unit->id]);
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

    public function test_a_receipt_keeps_the_supplier_the_invoice_number_and_the_unit_cost(): void
    {
        $staff = $this->userWithRole('staff');

        $this->actingAs($staff)
            ->post(route('admin.stocks.receive'), $this->receipt([
                'supplier_name' => 'PT Kabel Jaya',
                'purchase_reference' => 'INV-2026-0042',
                'unit_cost' => 12500.5,
            ]))
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $movement = StockMovement::query()->where('movement_type', 'receive')->firstOrFail();
        $this->assertSame('PT Kabel Jaya', $movement->supplier_name);
        $this->assertSame('INV-2026-0042', $movement->purchase_reference);
        $this->assertSame('12500.50', $movement->unit_cost);

        $audit = Activity::query()->where('log_name', 'inventory')->where('description', 'stock_received')->firstOrFail();
        $this->assertSame('PT Kabel Jaya', $audit->properties['supplier_name']);
        $this->assertSame('INV-2026-0042', $audit->properties['purchase_reference']);
        $this->assertArrayNotHasKey('unit_cost', $audit->properties->toArray(), 'a price is no audit detail');
    }

    public function test_a_receipt_without_details_stays_as_before(): void
    {
        $this->actingAs($this->userWithRole('staff'))
            ->post(route('admin.stocks.receive'), $this->receipt(['supplier_name' => '', 'purchase_reference' => '', 'unit_cost' => '']))
            ->assertSessionHasNoErrors();

        $movement = StockMovement::query()->where('movement_type', 'receive')->firstOrFail();
        $this->assertNull($movement->supplier_name);
        $this->assertNull($movement->purchase_reference);
        $this->assertNull($movement->unit_cost);

        $properties = Activity::query()->where('description', 'stock_received')->firstOrFail()->properties->toArray();
        $this->assertArrayNotHasKey('supplier_name', $properties);
        $this->assertArrayNotHasKey('purchase_reference', $properties);
    }

    public function test_only_a_receipt_carries_the_details(): void
    {
        $this->actingAs($this->userWithRole('staff'));
        StockService::receive($this->product->id, $this->locationA->id, 10, supplierName: 'PT Kabel Jaya');
        StockService::issue($this->product->id, $this->locationA->id, 2);

        $issue = StockMovement::query()->where('movement_type', 'issue')->firstOrFail();
        $this->assertNull($issue->supplier_name);
        $this->assertNull($issue->unit_cost);
    }

    public function test_the_details_are_validated(): void
    {
        $staff = $this->userWithRole('staff');

        $this->actingAs($staff)
            ->post(route('admin.stocks.receive'), $this->receipt([
                'supplier_name' => str_repeat('x', 151),
                'purchase_reference' => str_repeat('y', 101),
                'unit_cost' => -1,
            ]))
            ->assertSessionHasErrors(['supplier_name', 'purchase_reference', 'unit_cost']);

        $this->actingAs($staff)
            ->post(route('admin.stocks.receive'), $this->receipt(['unit_cost' => 'gratis']))
            ->assertSessionHasErrors('unit_cost');

        $this->assertSame(0, StockMovement::query()->count(), 'a refused receipt books nothing');
    }

    public function test_a_unit_cost_keeps_every_digit_it_was_typed_with_and_a_quantity_has_a_ceiling(): void
    {
        $staff = $this->userWithRole('staff');

        $this->actingAs($staff)
            ->post(route('admin.stocks.receive'), $this->receipt(['unit_cost' => '9999999999999.99']))
            ->assertSessionHasNoErrors();
        $this->assertSame('9999999999999.99', StockMovement::query()->where('movement_type', 'receive')->sole()->unit_cost, 'a price of fifteen digits does not pass through a float');

        foreach ([['unit_cost' => '12.345'], ['unit_cost' => '1e13'], ['quantity' => '1000000001']] as $bad) {
            $this->actingAs($staff)
                ->post(route('admin.stocks.receive'), $this->receipt($bad))
                ->assertSessionHasErrors(array_key_first($bad));
        }

        $this->assertSame(1, StockMovement::query()->where('movement_type', 'receive')->count(), 'the refused receipts booked nothing');
    }

    public function test_a_transfer_to_the_same_place_is_refused_on_the_to_field(): void
    {
        $staff = $this->userWithRole('staff');
        $this->actingAs($staff);
        StockService::receive($this->product->id, $this->locationA->id, 10);

        $this->post(route('admin.stocks.transfer'), [
            'product_id' => $this->product->public_id,
            'from_location_id' => $this->locationA->public_id,
            'to_location_id' => $this->locationA->public_id,
            'quantity' => 1,
        ])->assertSessionHasErrors(['to_location_id' => 'Lokasi tujuan harus berbeda dari lokasi asal.']);

        $this->assertSame(0, StockMovement::query()->where('movement_type', 'transfer')->count());
    }

    public function test_the_page_offers_the_transfer_only_to_who_may_transfer(): void
    {
        $viewer = $this->userWithPermissions('Peninjau stok', ['inventory.view']);

        $this->actingAs($this->userWithRole('staff'))
            ->get(route('admin.stocks.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('can.transfer', true)->missing('stockLookup'));

        $this->actingAs($viewer)
            ->get(route('admin.stocks.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('can.transfer', false));

        $this->actingAs($viewer)
            ->post(route('admin.stocks.transfer'), [
                'product_id' => $this->product->public_id,
                'from_location_id' => $this->locationA->public_id,
                'to_location_id' => $this->locationB->public_id,
                'quantity' => 1,
            ])
            ->assertForbidden();
    }

    public function test_the_lookup_answers_what_the_system_holds_of_one_pair(): void
    {
        $this->actingAs($this->userWithRole('staff'));
        StockService::receive($this->product->id, $this->locationA->id, 10);
        Stock::query()->where('location_id', $this->locationA->id)->update(['reserved_quantity' => 4]);

        $response = $this->lookup($this->product->public_id, $this->locationA->public_id);
        $this->assertEquals(
            ['quantity' => '10.00', 'reserved_quantity' => '4.00', 'available' => 6, 'unit' => 'PCS'],
            $response->json('props.stockLookup'),
        );

        $this->assertEquals(
            ['quantity' => '0.00', 'reserved_quantity' => '0.00', 'available' => 0, 'unit' => 'PCS'],
            $this->lookup($this->product->public_id, $this->locationB->public_id)->json('props.stockLookup'),
            'a place the product never reached holds nothing',
        );
    }

    public function test_the_lookup_answers_nothing_for_a_pair_it_cannot_name(): void
    {
        $this->actingAs($this->userWithRole('staff'));
        $other = Company::factory()->create();
        $foreign = Product::factory()->create(['company_id' => $other->id]);

        $this->assertNull($this->lookup($foreign->public_id, $this->locationA->public_id)->json('props.stockLookup'));
        $this->assertNull($this->lookup($this->product->public_id, 'not-a-ulid')->json('props.stockLookup'));
        $this->assertNull($this->lookup((string) $this->product->id, (string) $this->locationA->id)->json('props.stockLookup'), 'an integer id names nothing');
    }

    private function lookup(string $productId, string $locationId): TestResponse
    {
        return $this->withHeaders([
            'X-Inertia' => 'true',
            'X-Inertia-Version' => app(HandleInertiaRequests::class)->version(request()),
            'X-Inertia-Partial-Component' => 'Admin/Inventory/Stocks/Index',
            'X-Inertia-Partial-Data' => 'stockLookup',
        ])->get(route('admin.stocks.index', ['lookup_product_id' => $productId, 'lookup_location_id' => $locationId]));
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create(['company_id' => $this->company->id]);
        $this->grantRole($user, $role);

        return $user;
    }

    /** @param  list<string>  $permissions */
    private function userWithPermissions(string $roleName, array $permissions): User
    {
        $user = User::factory()->create(['company_id' => $this->company->id]);
        $this->grantRole($user, $this->companyRole($this->company, $roleName, $permissions));

        return $user;
    }

    private function makeLocation(string $code): Location
    {
        return Location::query()->create(['name' => $code, 'code' => $code, 'type' => 'site', 'is_active' => true]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function receipt(array $overrides = []): array
    {
        return array_merge([
            'product_id' => $this->product->public_id,
            'location_id' => $this->locationA->public_id,
            'quantity' => 5,
            'note' => 'test',
        ], $overrides);
    }
}
