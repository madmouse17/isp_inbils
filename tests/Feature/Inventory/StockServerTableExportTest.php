<?php

namespace Tests\Feature\Inventory;

use App\Models\Core\Company;
use App\Models\Core\Location;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\Stock;
use Modules\Inventory\Models\StockMovement;
use Tests\TestCase;

class StockServerTableExportTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Location $location;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->admin = User::factory()->create([
            'company_id' => Company::factory()->create(['is_active' => true])->id,
            'is_active' => true,
            'email_verified_at' => now(),
        ]);
        $this->admin->assignRole('admin');
        $this->actingAs($this->admin);
        $this->location = Location::factory()->create(['company_id' => $this->admin->company_id]);
    }

    public function test_stock_page_sizes_sort_directions_and_export_include_all_filtered_rows(): void
    {
        $ids = [];
        for ($quantity = 1; $quantity <= 51; $quantity++) {
            $stock = $this->stock($quantity, 100, 'Table stock '.$quantity);
            $ids[] = $stock->public_id;
        }

        foreach ([10, 25, 50] as $size) {
            foreach (['asc', 'desc'] as $direction) {
                $expected = $direction === 'asc' ? $ids : array_reverse($ids);
                $query = ['search' => 'Table stock', 'location_id' => $this->location->public_id, 'sort_by' => 'quantity', 'sort_dir' => $direction, 'per_page' => $size];
                $this->get(route('admin.stocks.index', $query))->assertOk()
                    ->assertInertia(fn (Assert $page) => $page
                        ->has('stocks.data', $size)
                        ->where('stocks.meta.per_page', $size)
                        ->where('stocks.meta.total', 51)
                        ->where('stocks.data.0.id', $expected[0])
                        ->where('stocks.data.'.($size - 1).'.id', $expected[$size - 1])
                        ->where('stocks.links.next', fn ($url) => $this->queryMatches($url, [...$query, 'page' => 2]))
                    );
                $response = $this->get(route('admin.stocks.export', [...$query, 'page' => 2, 'format' => 'csv']))->assertOk();
                $rows = $this->csvRows($response->streamedContent());
                $this->assertSame(array_map('strval', $direction === 'asc' ? range(1, 51) : range(51, 1)), array_map(fn ($row) => (string) (int) $row[2], $rows));
            }
        }
    }

    public function test_stock_low_filter_uses_total_quantity_inclusive_threshold_and_company_scope(): void
    {
        $below = $this->stock(4, 5, 'Boundary below');
        $equal = $this->stock(5, 5, 'Boundary equal');
        $equal->product()->update(['is_active' => false]);
        $this->stock(6, 5, 'Boundary above', 5);
        $zero = $this->stock(0, 0, 'Boundary zero');
        $this->stock(1, 0, 'Boundary positive');
        $other = User::factory()->create(['company_id' => Company::factory()->create()->id]);
        $this->actingAs($other);
        $foreignProduct = Product::factory()->create(['company_id' => $other->company_id, 'name' => 'Boundary foreign', 'min_stock' => 5]);
        Stock::query()->create(['product_id' => $foreignProduct->id, 'location_id' => Location::factory()->create(['company_id' => $other->company_id])->id, 'quantity' => 0, 'reserved_quantity' => 0]);
        $this->actingAs($this->admin);

        $query = ['search' => 'Boundary', 'low_stock' => 1, 'sort_by' => 'quantity', 'sort_dir' => 'asc'];
        $this->get(route('admin.stocks.index', $query))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('stocks.meta.total', 3)
            ->where('stocks.data.0.id', $zero->public_id)
            ->where('stocks.data.1.id', $below->public_id)
            ->where('stocks.data.2.id', $equal->public_id)
            ->where('stocks.data.2.product.name', 'Boundary equal')
        );
        $rows = $this->csvRows($this->get(route('admin.stocks.export', $query))->assertOk()->streamedContent());
        $this->assertSame(['Boundary zero', 'Boundary below', 'Boundary equal'], array_column($rows, 0));
        $this->get(route('admin.stocks.index', ['product_id' => $foreignProduct->public_id, 'low_stock' => 1]))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('stocks.meta.total', 0));
    }

    public function test_low_stock_keeps_zero_stock_rows_after_product_soft_deletion(): void
    {
        $stock = $this->stock(0, 5, 'Deleted product');
        $stock->product->delete();
        $query = ['low_stock' => 1, 'product_id' => $stock->product->public_id];

        $this->get(route('admin.stocks.index', $query))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('stocks.meta.total', 1)
            ->where('stocks.data.0.id', $stock->public_id)
            ->where('stocks.data.0.product.name', 'Deleted product')
            ->whereNot('stocks.data.0.product.deleted_at', null)
        );
        $rows = $this->csvRows($this->get(route('admin.stocks.export', $query))->assertOk()->streamedContent());
        $this->assertCount(1, $rows);
        $this->assertSame(0, (int) $rows[0][2]);
    }

    public function test_movement_page_sizes_sort_directions_and_export_include_all_filtered_rows(): void
    {
        $product = Product::factory()->create(['company_id' => $this->admin->company_id, 'name' => 'Movement table']);
        $ids = [];
        foreach (range(1, 51) as $quantity) {
            $movement = StockMovement::query()->create([
                'product_id' => $product->id,
                'to_location_id' => $this->location->id,
                'movement_type' => 'receive',
                'quantity' => $quantity,
                'balance_after' => $quantity,
                'reserved_after' => 0,
                'note' => 'Movement table '.$quantity,
                'created_by' => $this->admin->id,
            ]);
            $ids[] = $movement->public_id;
        }
        foreach ([10, 25, 50] as $size) {
            foreach (['asc', 'desc'] as $direction) {
                $expected = $direction === 'asc' ? $ids : array_reverse($ids);
                $query = ['product_id' => $product->public_id, 'location_id' => $this->location->public_id, 'movement_type' => 'receive', 'search' => 'Movement table', 'sort_by' => 'quantity', 'sort_dir' => $direction, 'per_page' => $size];
                $this->get(route('admin.stock-movements.index', $query))->assertOk()->assertInertia(fn (Assert $page) => $page
                    ->has('movements.data', $size)
                    ->where('movements.meta.per_page', $size)
                    ->where('movements.meta.total', 51)
                    ->where('movements.data.0.id', $expected[0])
                    ->where('movements.data.'.($size - 1).'.id', $expected[$size - 1])
                    ->where('movements.links.next', fn ($url) => $this->queryMatches($url, [...$query, 'page' => 2]))
                );
                $rows = $this->csvRows($this->get(route('admin.stock-movements.export', [...$query, 'page' => 2]))->assertOk()->streamedContent());
                $this->assertSame($direction === 'asc' ? range(1, 51) : range(51, 1), array_map(fn ($row) => (int) $row[4], $rows));
            }
        }
    }

    public function test_invalid_sort_keys_do_not_cross_stock_and_movement_allowlists(): void
    {
        $older = $this->stock(1, 5, 'Older');
        $older->forceFill(['created_at' => now()->subDay()])->save();
        $newer = $this->stock(2, 5, 'Newer');
        $this->get(route('admin.stocks.index', ['sort_by' => 'movement_type', 'per_page' => 999]))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('stocks.data.0.id', $newer->public_id)->where('stocks.meta.per_page', 10));
        $this->movement($older->product_id, 1, 'Older movement', now()->subDay());
        $latest = $this->movement($newer->product_id, 2, 'Latest movement', now());
        $this->get(route('admin.stock-movements.index', ['sort_by' => 'reserved_quantity', 'per_page' => 999]))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('movements.meta.per_page', 10)->where('movements.data.0.id', $latest->public_id));
    }

    public function test_movement_exports_match_filtered_tenant_rows_in_csv_and_pdf(): void
    {
        $stock = $this->stock(5, 5, 'Export product');
        $first = $this->movement($stock->product_id, 2, 'Scoped first', now()->subDay());
        $this->movement($stock->product_id, 4, 'Scoped second', now());
        $this->movement($stock->product_id, 8, 'Excluded note', now());
        $other = User::factory()->create(['company_id' => Company::factory()->create()->id]);
        $this->actingAs($other);
        $foreignProduct = Product::factory()->create(['company_id' => $other->company_id]);
        $foreignLocation = Location::factory()->create(['company_id' => $other->company_id]);
        StockMovement::query()->create([
            'product_id' => $foreignProduct->id,
            'to_location_id' => $foreignLocation->id,
            'movement_type' => 'receive',
            'quantity' => 1,
            'balance_after' => 1,
            'reserved_after' => 0,
            'note' => 'Scoped foreign',
            'created_by' => $other->id,
        ]);
        $this->actingAs($this->admin);
        $query = ['search' => 'Scoped', 'product_id' => $stock->product->public_id, 'location_id' => $this->location->public_id, 'movement_type' => 'receive', 'sort_by' => 'quantity', 'sort_dir' => 'asc', 'per_page' => 10];
        $this->get(route('admin.stock-movements.index', $query))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('movements.meta.total', 2)->where('movements.data.0.id', $first->public_id));
        $csv = $this->get(route('admin.stock-movements.export', [...$query, 'format' => 'csv', 'page' => 2]))->assertOk()->streamedContent();
        $this->assertStringContainsString('Scoped first', $csv);
        $this->assertStringContainsString('Scoped second', $csv);
        $this->assertStringNotContainsString('Excluded note', $csv);
        $this->assertStringNotContainsString('Scoped foreign', $csv);
        $this->assertTrue(strpos($csv, 'Scoped first') < strpos($csv, 'Scoped second'));

        $pdf = $this->get(route('admin.stock-movements.export', [...$query, 'format' => 'pdf', 'page' => 2]))->assertOk();
        $this->assertStringStartsWith('%PDF-', $pdf->streamedContent());
        $this->get(route('admin.stock-movements.index', ['product_id' => $foreignProduct->public_id]))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('movements.meta.total', 0));
        $pdf = $this->get(route('admin.stocks.export', ['format' => 'pdf', 'product_id' => $stock->product->public_id]))->assertOk();
        $this->assertStringStartsWith('%PDF-', $pdf->streamedContent());
    }

    public function test_movement_filters_with_foreign_ids_never_return_foreign_rows(): void
    {
        $own = $this->stock(5, 5, 'Own product');
        $this->movement($own->product_id, 3, 'Own ledger row', now());

        $foreignCompany = Company::factory()->create(['is_active' => true]);
        $foreignProduct = Product::factory()->create(['company_id' => $foreignCompany->id]);
        $foreignLocation = Location::factory()->create(['company_id' => $foreignCompany->id]);
        StockMovement::withoutCompany()->forceCreate([
            'company_id' => $foreignCompany->id,
            'product_id' => $foreignProduct->id,
            'to_location_id' => $foreignLocation->id,
            'movement_type' => 'receive',
            'quantity' => 9,
            'balance_after' => 9,
            'reserved_after' => 0,
            'created_by' => $this->admin->id,
            'note' => 'Foreign ledger row',
        ]);
        $this->assertDatabaseHas('stock_movements', ['note' => 'Foreign ledger row', 'to_location_id' => $foreignLocation->id]);

        foreach ([
            ['location_id' => $foreignLocation->public_id],
            ['product_id' => $foreignProduct->public_id],
            ['product_id' => $foreignProduct->public_id, 'location_id' => $foreignLocation->public_id],
        ] as $filters) {
            $this->get(route('admin.stock-movements.index', $filters))->assertOk()
                ->assertInertia(fn (Assert $page) => $page->where('movements.meta.total', 0)->has('movements.data', 0));
            $csv = $this->get(route('admin.stock-movements.export', [...$filters, 'format' => 'csv']))->assertOk()->streamedContent();
            $this->assertStringNotContainsString('Foreign ledger row', $csv, json_encode($filters));
            $this->assertStringNotContainsString('Own ledger row', $csv, json_encode($filters));
        }
    }

    public function test_direct_stock_and_movement_exports_require_export_permission(): void
    {
        $viewer = User::factory()->create(['company_id' => $this->admin->company_id, 'is_active' => true, 'email_verified_at' => now()]);
        $viewer->givePermissionTo('inventory.view');
        $this->actingAs($viewer);
        foreach (['admin.stocks.export', 'admin.stock-movements.export'] as $route) {
            foreach (['csv', 'pdf'] as $format) {
                $this->get(route($route, ['format' => $format]))->assertForbidden();
            }
        }
    }

    private function movement(int $productId, int $quantity, string $note, Carbon $createdAt): StockMovement
    {
        $movement = new StockMovement([
            'product_id' => $productId, 'to_location_id' => $this->location->id,
            'movement_type' => 'receive', 'quantity' => $quantity,
            'balance_after' => $quantity, 'reserved_after' => 0, 'note' => $note,
            'created_by' => auth()->id(),
        ]);
        $movement->created_at = $createdAt;
        $movement->save();

        return $movement;
    }

    private function queryMatches(string $url, array $expected): bool
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $actual);

        foreach ($expected as $key => $value) {
            if (($actual[$key] ?? null) !== (string) $value) {
                return false;
            }
        }

        return true;
    }

    private function stock(int $quantity, int $minimum, string $name, int $reserved = 0): Stock
    {
        $product = Product::factory()->create(['company_id' => $this->admin->company_id, 'name' => $name, 'min_stock' => $minimum]);

        return Stock::query()->create(['product_id' => $product->id, 'location_id' => $this->location->id, 'quantity' => $quantity, 'reserved_quantity' => $reserved]);
    }

    private function csvRows(string $content): array
    {
        $lines = preg_split('/\r\n|\n|\r/', trim($content));
        array_shift($lines);

        return array_map(fn ($line) => str_getcsv($line), $lines);
    }
}
