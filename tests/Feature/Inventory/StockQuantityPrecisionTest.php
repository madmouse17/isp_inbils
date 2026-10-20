<?php

namespace Tests\Feature\Inventory;

use App\Models\Core\Location;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Inventory\Exceptions\InsufficientStockException;
use Modules\Inventory\Models\InventoryRequest;
use Modules\Inventory\Models\InventoryRequestItem;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\Stock;
use Modules\Inventory\Models\Unit;
use Modules\Inventory\Services\InventoryRequestService;
use Modules\Inventory\Services\StockService;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;
use Tests\Traits\CreatesCompanyUser;

/**
 * Stock is decimal(15,2): whether a quantity fits is decided on cents with bcmath, never on floats. 100.10 minus
 * 100.00 is 0.10, not the 0.0999999999999943 that float arithmetic gives, so the last fraction of stock can be
 * issued, transferred, reserved, released and approved, and one cent over still fails.
 */
class StockQuantityPrecisionTest extends TestCase
{
    use CreatesCompanyUser;
    use RefreshDatabase;

    private User $user;

    private Product $product;

    private Location $from;

    private Location $to;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = $this->createCompanyUser();
        $this->actingAs($this->user);
        $this->product = Product::factory()->create(['company_id' => $this->user->company_id, 'name' => 'Kabel Drop']);
        Unit::withoutCompany()->whereKey($this->product->unit_id)->update(['symbol' => 'm']);
        $this->from = $this->location('WH-A');
        $this->to = $this->location('WH-B');
    }

    public function test_issuing_exactly_the_available_fraction_passes(): void
    {
        $this->stockAt($this->from, '100.10', '100.00');

        $this->assertShort(fn () => StockService::issue($this->product->id, $this->from->id, 0.11), 'Stok Kabel Drop di WH-A tersisa 0,1 m, dibutuhkan 0,11 m.');

        StockService::issue($this->product->id, $this->from->id, 0.10);

        $this->assertSame(['100.00', '100.00'], $this->stockOf($this->from));
    }

    public function test_transferring_exactly_the_available_fraction_passes(): void
    {
        $this->stockAt($this->from, '100.10', '100.00');

        $this->assertShort(fn () => StockService::transfer($this->product->id, $this->from->id, $this->to->id, 0.11), 'Stok Kabel Drop di WH-A tersisa 0,1 m, dibutuhkan 0,11 m.');

        StockService::transfer($this->product->id, $this->from->id, $this->to->id, 0.10);

        $this->assertSame(['100.00', '100.00'], $this->stockOf($this->from));
        $this->assertSame(['0.10', '0.00'], $this->stockOf($this->to));
    }

    public function test_reserving_exactly_the_available_fraction_passes(): void
    {
        $this->stockAt($this->from, '100.10', '100.00');

        $this->assertShort(fn () => StockService::reserve($this->product->id, $this->from->id, 0.11), 'Stok Kabel Drop di WH-A tersisa 0,1 m, dibutuhkan 0,11 m.');

        StockService::reserve($this->product->id, $this->from->id, 0.10);

        $this->assertSame(['100.10', '100.10'], $this->stockOf($this->from));
    }

    public function test_releasing_a_reservation_computed_with_floats_passes(): void
    {
        $this->stockAt($this->from, '5.00', '0.30');

        $this->assertShort(fn () => StockService::release($this->product->id, $this->from->id, 0.31), 'Cadangan Kabel Drop di WH-A hanya 0,3 m, tidak bisa dilepas 0,31 m.');

        // 0.1 + 0.2 is 0.30000000000000004 as a float: the reservation of 0.30 still covers it.
        StockService::release($this->product->id, $this->from->id, 0.1 + 0.2);

        $this->assertSame(['5.00', '0.00'], $this->stockOf($this->from));
    }

    public function test_a_stock_movement_of_exactly_the_available_fraction_passes(): void
    {
        $this->stockAt($this->from, '100.10', '100.00');

        $this->assertShort(fn () => StockService::stockMovement($this->product->id, $this->from->id, null, 'issue', 0.11), 'Stok Kabel Drop di WH-A tersisa 0,1 m, dibutuhkan 0,11 m.');

        StockService::stockMovement($this->product->id, $this->from->id, null, 'issue', 0.10);
        $this->assertSame(['100.00', '100.00'], $this->stockOf($this->from));

        $this->stockAt($this->to, '100.10', '100.00');
        StockService::stockMovement($this->product->id, $this->to->id, $this->from->id, 'transfer', 0.10);

        $this->assertSame(['100.00', '100.00'], $this->stockOf($this->to));
        $this->assertSame(['100.10', '100.00'], $this->stockOf($this->from));
    }

    public function test_an_adjustment_down_to_exactly_the_reserved_quantity_passes(): void
    {
        $this->stockAt($this->from, '5.00', '0.30');

        $this->assertShort(fn () => StockService::adjust($this->product->id, $this->from->id, 0.29, 'Hitung ulang'), 'Stok Kabel Drop di WH-A tidak bisa diturunkan ke 0,29 m, karena 0,3 m sudah dicadangkan.');

        // 0.7 - 0.4 is 0.29999999999999993 as a float: it is the reserved 0.30 to the cent.
        StockService::adjust($this->product->id, $this->from->id, 0.7 - 0.4, 'Hitung ulang');

        $this->assertSame(['0.30', '0.30'], $this->stockOf($this->from));
    }

    public function test_approving_a_request_for_exactly_the_available_fraction_passes(): void
    {
        $this->stockAt($this->from, '100.10', '100.00');
        $requester = User::factory()->create(['company_id' => $this->user->company_id]);

        $tooMuch = $this->submittedRequest($requester, 0.11);
        try {
            InventoryRequestService::approve($tooMuch, $this->user);
            $this->fail('A cent over the available stock cannot be approved.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertSame("Stok tidak mencukupi untuk {$this->product->sku} di {$this->from->code}. Tersedia: 0.1, Diminta: 0.11.", $e->getMessage());
        }
        $this->assertSame(['100.10', '100.00'], $this->stockOf($this->from));

        InventoryRequestService::approve($this->submittedRequest($requester, 0.10), $this->user);

        $this->assertSame(['100.10', '100.10'], $this->stockOf($this->from));
    }

    private function location(string $code): Location
    {
        return Location::factory()->create(['company_id' => $this->user->company_id, 'type' => 'site', 'code' => $code, 'name' => $code]);
    }

    private function stockAt(Location $location, string $quantity, string $reserved): void
    {
        Stock::query()->create([
            'company_id' => $this->user->company_id,
            'product_id' => $this->product->id,
            'location_id' => $location->id,
            'quantity' => $quantity,
            'reserved_quantity' => $reserved,
        ]);
    }

    /** @return array{0: string, 1: string} quantity and reserved quantity, as stored */
    private function stockOf(Location $location): array
    {
        $stock = Stock::withoutCompany()->where('product_id', $this->product->id)->where('location_id', $location->id)->sole();

        return [$stock->quantity, $stock->reserved_quantity];
    }

    private function submittedRequest(User $requester, float $quantity): InventoryRequest
    {
        $request = InventoryRequest::query()->create([
            'company_id' => $this->user->company_id,
            'code' => 'REQ-'.fake()->unique()->numerify('######'),
            'requested_by' => $requester->id,
            'warehouse_location_id' => $this->from->id,
            'status' => 'submitted',
            'reason' => 'Presisi stok',
        ]);
        InventoryRequestItem::query()->create([
            'company_id' => $this->user->company_id,
            'inventory_request_id' => $request->id,
            'product_id' => $this->product->id,
            'source_location_id' => $this->from->id,
            'quantity_requested' => $quantity,
        ]);

        return $request;
    }

    private function assertShort(callable $operation, string $message): void
    {
        try {
            $operation();
            $this->fail('One cent over the available quantity must be refused.');
        } catch (InsufficientStockException $e) {
            $this->assertSame($message, $e->getMessage());
        }
    }
}
