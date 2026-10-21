<?php

namespace Tests\Feature\Reporting;

use App\Models\Core\Location;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\Models\Category;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\StockMovement;
use Modules\Inventory\Models\Unit;
use Tests\TestCase;
use Tests\Traits\CreatesReportingUsers;

/**
 * K33 (part): the Stock Card, before a product is chosen, offers the eight products whose stock moved last, newest first,
 * read in one query of the ledger and only for the company of the viewer.
 */
class StockCardRecentProductsTest extends TestCase
{
    use CreatesReportingUsers;
    use RefreshDatabase;

    private User $admin;

    private int $companyId;

    private Location $location;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->userWithRole('admin');
        $this->companyId = (int) $this->admin->company_id;
        $this->location = Location::factory()->create(['company_id' => $this->companyId]);
        $this->actingAs($this->admin);
    }

    public function test_the_eight_products_that_moved_last_are_offered_newest_first_once_each(): void
    {
        $products = [];
        foreach (range(1, 10) as $n) {
            $products[$n] = $this->product($this->companyId, sprintf('Barang %02d', $n));
            $this->movement($products[$n], sprintf('2026-01-%02d 10:00:00', $n));
        }
        // Barang 02 moves again, last: it is listed once, first.
        $this->movement($products[2], '2026-01-20 10:00:00');
        // Another company's product, moved after all of them, never shows.
        $foreign = $this->product((int) $this->userWithRole('admin')->company_id, 'Barang Asing');
        $this->movement($foreign, '2026-01-25 10:00:00');
        DB::flushQueryLog();
        DB::enableQueryLog();

        $recent = $this->get(route('admin.reports.stock-card'))->assertOk()->inertiaProps('recentProducts');

        $this->assertSame(
            ['Barang 02', 'Barang 10', 'Barang 09', 'Barang 08', 'Barang 07', 'Barang 06', 'Barang 05', 'Barang 04'],
            array_column($recent, 'label'),
        );
        $this->assertSame($products[2]->public_id, $recent[0]['value']);
        $this->assertSame($products[2]->sku, $recent[0]['description']);
        $this->assertSame('2026-01-20T10:00:00+00:00', $recent[0]['last_moved_at'], 'the moment of its last movement');
        $this->assertSame(['value', 'label', 'description', 'last_moved_at'], array_keys($recent[0]), 'no integer id leaves the server');
        $this->assertCount(1, array_filter(DB::getQueryLog(), fn (array $query): bool => str_contains($query['query'], 'stock_movements')), 'one query of the ledger');
    }

    public function test_a_product_that_was_deleted_since_is_still_offered_and_a_chosen_product_has_no_list(): void
    {
        $gone = $this->product($this->companyId, 'Barang Lama');
        $this->movement($gone, '2026-01-10 10:00:00');
        $gone->delete();

        $recent = $this->get(route('admin.reports.stock-card'))->assertOk()->inertiaProps('recentProducts');
        $this->assertSame(['Barang Lama'], array_column($recent, 'label'), 'the ledger keeps the rows of a deleted product');

        $this->assertNull($this->get(route('admin.reports.stock-card', ['product_id' => $gone->public_id]))->assertOk()->inertiaProps('recentProducts'));
    }

    private function product(int $companyId, string $name): Product
    {
        $unit = Unit::factory()->create(['company_id' => $companyId]);
        $category = Category::factory()->create(['company_id' => $companyId, 'unit_id' => $unit->id]);

        return Product::factory()->create(['company_id' => $companyId, 'category_id' => $category->id, 'unit_id' => $unit->id, 'name' => $name]);
    }

    private function movement(Product $product, string $createdAt): StockMovement
    {
        return StockMovement::withoutCompany()->forceCreate([
            'company_id' => $product->company_id,
            'product_id' => $product->id,
            'to_location_id' => $this->location->id,
            'movement_type' => 'receive',
            'quantity' => 1,
            'balance_after' => 1,
            'reserved_after' => 0,
            'created_by' => $this->admin->id,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }
}
