<?php

namespace Tests\Feature\Inventory;

use App\Http\Middleware\PreventDuplicateWebSubmit;
use App\Models\Core\Location;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\Stock;
use Modules\Inventory\Models\StockMovement;
use Tests\TestCase;
use Tests\Traits\CreatesCompanyUser;

/**
 * A double click on a stock form sends the same write twice. The second one is refused by the duplicate-submit guard
 * (switched on here: it is off in `testing`), so stock moves once.
 */
class StockFormReplayTest extends TestCase
{
    use CreatesCompanyUser;
    use RefreshDatabase;

    private User $admin;

    private Product $product;

    private Location $warehouse;

    private Location $van;

    protected function setUp(): void
    {
        parent::setUp();

        config(['security.duplicate_submit.enabled' => true]);
        $this->admin = $this->createCompanyUser();
        $this->product = Product::factory()->create(['company_id' => $this->admin->company_id]);
        $this->warehouse = Location::factory()->create(['company_id' => $this->admin->company_id, 'type' => 'site']);
        $this->van = Location::factory()->create(['company_id' => $this->admin->company_id, 'type' => 'site']);
        Stock::query()->create(['company_id' => $this->admin->company_id, 'product_id' => $this->product->id, 'location_id' => $this->warehouse->id, 'quantity' => 100, 'reserved_quantity' => 0]);
    }

    public function test_receiving_issuing_or_transferring_twice_moves_stock_once(): void
    {
        $this->actingAs($this->admin)->withHeader('X-Inertia', 'true');

        $forms = [
            ['admin.stocks.receive', ['product_id' => $this->product->public_id, 'location_id' => $this->warehouse->public_id, 'quantity' => 10, 'note' => 'Barang masuk'], '110.00', '0.00', 1],
            ['admin.stocks.issue', ['product_id' => $this->product->public_id, 'location_id' => $this->warehouse->public_id, 'quantity' => 4, 'note' => 'Barang keluar'], '106.00', '0.00', 2],
            ['admin.stocks.transfer', ['product_id' => $this->product->public_id, 'from_location_id' => $this->warehouse->public_id, 'to_location_id' => $this->van->public_id, 'quantity' => 6, 'note' => 'Isi mobil'], '100.00', '6.00', 4],
        ];

        foreach ($forms as [$route, $body, $warehouse, $van, $movements]) {
            $this->post(route($route), $body)->assertRedirect()->assertSessionHasNoErrors();
            $this->post(route($route), $body)
                ->assertRedirect()
                ->assertSessionHasErrors(['conflict' => PreventDuplicateWebSubmit::MESSAGE])
                ->assertSessionHas('error', PreventDuplicateWebSubmit::MESSAGE);

            $this->assertSame($warehouse, $this->quantityAt($this->warehouse), $route);
            $this->assertSame($van, $this->quantityAt($this->van), $route);
            $this->assertSame($movements, StockMovement::withoutCompany()->where('product_id', $this->product->id)->count(), $route.' wrote one movement per submit');

            // The browser follows the refusal's redirect with a GET that reads the flash; the next form starts without it.
            $this->flushSession();
        }
    }

    private function quantityAt(Location $location): string
    {
        return (string) (Stock::withoutCompany()->where('product_id', $this->product->id)->where('location_id', $location->id)->value('quantity') ?? '0.00');
    }
}
