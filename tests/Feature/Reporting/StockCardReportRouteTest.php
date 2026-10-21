<?php

namespace Tests\Feature\Reporting;

use App\Models\Core\Company;
use App\Models\Core\Location;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Inventory\Models\Category;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\StockMovement;
use Modules\Inventory\Models\Unit;
use Tests\TestCase;

class StockCardReportRouteTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Location $location;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $company = Company::factory()->create(['is_active' => true]);
        $this->admin = User::factory()->create([
            'company_id' => $company->id,
            'is_active' => true,
            'email_verified_at' => now(),
        ]);
        $this->grantRole($this->admin, 'admin');
        $this->actingAs($this->admin);
        $this->location = Location::factory()->create(['company_id' => $company->id]);
    }

    public function test_stock_card_route_paginates_sorts_and_preserves_product_and_date_filters(): void
    {
        $product = $this->productForCompany($this->admin->company_id);
        foreach (range(1, 27) as $quantity) {
            $this->movement($product, $quantity, now()->startOfDay()->addMinutes($quantity));
        }
        $this->movement($product, 99, now()->subDays(3));

        $filters = [
            'product_id' => $product->public_id,
            'location_id' => $this->location->public_id,
            'date_from' => now()->toDateString(),
            'date_to' => now()->toDateString(),
            'sort_by' => 'quantity',
            'per_page' => 10,
        ];

        $this->get(route('admin.reports.stock-card', [...$filters, 'sort_dir' => 'asc']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('filters.product_id', $product->public_id)
                ->where('filters.date_from', $filters['date_from'])
                ->where('movements.meta.total', 27)
                ->where('movements.meta.per_page', 10)
                ->has('movements.data', 10)
                ->where('movements.data.0.quantity', '1.00')
                ->where('movements.data.9.quantity', '10.00')
                ->where('movements.links.next', fn ($url) => is_string($url) && str_contains($url, 'page=2'))
            );

        $this->get(route('admin.reports.stock-card', [...$filters, 'per_page' => 25, 'sort_dir' => 'desc']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('movements.meta.total', 27)
                ->where('movements.meta.per_page', 25)
                ->has('movements.data', 25)
                ->where('movements.data.0.quantity', '27.00')
                ->where('movements.data.24.quantity', '3.00')
                ->where('movements.links.next', fn ($url) => is_string($url) && str_contains($url, 'product_id='.$product->public_id))
            );
    }

    public function test_stock_card_route_accepts_the_sort_and_direction_keys_sent_by_the_page(): void
    {
        $product = $this->productForCompany($this->admin->company_id);
        foreach ([5, 2, 9] as $minute => $quantity) {
            $this->movement($product, $quantity, now()->startOfDay()->addMinutes($minute));
        }

        $this->get(route('admin.reports.stock-card', [
            'product_id' => $product->public_id,
            'sort' => 'quantity',
            'direction' => 'asc',
            'per_page' => 10,
        ]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('filters.sort_by', 'quantity')
                ->where('filters.sort_dir', 'asc')
                ->where('movements.data.0.quantity', '2.00')
                ->where('movements.data.2.quantity', '9.00')
            );

        $this->get(route('admin.reports.stock-card', ['product_id' => $product->public_id, 'sort' => 'event']))
            ->assertSessionHasErrors('sort');
    }

    public function test_stock_card_keeps_the_ledger_of_a_soft_deleted_same_company_product(): void
    {
        $product = $this->productForCompany($this->admin->company_id);
        $this->movement($product, 7, now());
        $product->delete();

        $this->get(route('admin.reports.stock-card', ['product_id' => $product->public_id]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('movements.meta.total', 1)
                ->where('movements.data.0.quantity', '7.00')
            );
    }

    public function test_stock_card_route_fails_closed_for_a_foreign_company_product(): void
    {
        $foreignCompany = Company::factory()->create(['is_active' => true]);
        $foreignUnit = Unit::factory()->create(['company_id' => $foreignCompany->id]);
        $foreignCategory = Category::factory()->create([
            'company_id' => $foreignCompany->id,
            'unit_id' => $foreignUnit->id,
        ]);
        $foreignProduct = Product::withoutCompany()->forceCreate(
            Product::factory()->make([
                'company_id' => $foreignCompany->id,
                'category_id' => $foreignCategory->id,
                'unit_id' => $foreignUnit->id,
            ])->getAttributes()
        );
        $foreignLocation = Location::factory()->create(['company_id' => $foreignCompany->id]);
        $this->movement($foreignProduct, 45, now(), $foreignLocation);

        $this->get(route('admin.reports.stock-card', ['product_id' => $foreignProduct->public_id]))
            ->assertSessionHasErrors('product_id');
    }

    public function test_stock_card_location_filter_never_reaches_a_foreign_company_row(): void
    {
        $product = $this->productForCompany($this->admin->company_id);
        $this->movement($product, 7, now());

        $foreignCompany = Company::factory()->create(['is_active' => true]);
        $foreignLocation = Location::factory()->create(['company_id' => $foreignCompany->id]);
        // A row of the other company that points at this very product and at a location of its own.
        StockMovement::withoutCompany()->forceCreate([
            'company_id' => $foreignCompany->id,
            'product_id' => $product->id,
            'to_location_id' => $foreignLocation->id,
            'movement_type' => 'receive',
            'quantity' => 45,
            'balance_after' => 45,
            'reserved_after' => 0,
            'created_by' => $this->admin->id,
            'note' => 'Foreign ledger row',
        ]);
        $this->assertDatabaseHas('stock_movements', ['note' => 'Foreign ledger row', 'product_id' => $product->id, 'to_location_id' => $foreignLocation->id]);
        $filters = ['product_id' => $product->public_id, 'location_id' => $foreignLocation->public_id];

        // The foreign location is a validation error on the screen and on the file: no ledger is read at all.
        $this->get(route('admin.reports.stock-card', $filters))->assertSessionHasErrors('location_id');
        $this->get(route('admin.reports.stock-card.export', $filters))->assertSessionHasErrors('location_id');
    }

    private function productForCompany(int $companyId): Product
    {
        $unit = Unit::factory()->create(['company_id' => $companyId]);
        $category = Category::factory()->create([
            'company_id' => $companyId,
            'unit_id' => $unit->id,
        ]);

        return Product::factory()->create([
            'company_id' => $companyId,
            'category_id' => $category->id,
            'unit_id' => $unit->id,
        ]);
    }

    private function movement(Product $product, int $quantity, $createdAt, ?Location $location = null): StockMovement
    {
        return StockMovement::withoutCompany()->forceCreate([
            'company_id' => $product->company_id,
            'product_id' => $product->id,
            'to_location_id' => ($location ?? $this->location)->id,
            'movement_type' => 'receive',
            'quantity' => $quantity,
            'balance_after' => $quantity,
            'reserved_after' => 0,
            'created_by' => $this->admin->id,
            'note' => 'Reporting fixture',
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }
}
