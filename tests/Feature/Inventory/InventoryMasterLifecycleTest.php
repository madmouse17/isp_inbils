<?php

namespace Tests\Feature\Inventory;

use App\Models\Core\Company;
use App\Models\Core\Location;
use App\Models\User;
use App\Support\OptimisticLock;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Modules\Billing\Database\Factories\InvoiceFactory;
use Modules\Billing\Models\InvoiceItem;
use Modules\Inventory\Models\Category;
use Modules\Inventory\Models\InventoryRequest;
use Modules\Inventory\Models\InventoryRequestItem;
use Modules\Inventory\Models\MaterialReturnCase;
use Modules\Inventory\Models\MaterialReturnLine;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\Stock;
use Modules\Inventory\Models\StockAdjustmentRequest;
use Modules\Inventory\Models\StockMovement;
use Modules\Inventory\Models\Unit;
use Modules\SPK\Database\Factories\WorkOrderFactory;
use Modules\SPK\Models\WorkOrderItem;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use Tests\Traits\CreatesCompanyUser;

/**
 * The inventory masters (spec .scratch/master-lifecycle): a product, category or unit that something points at is
 * deactivated, never deleted, and a hard delete happens only when nothing ever pointed at it. The status filter,
 * Pulihkan, the unit pickers and the history relations follow the same rule.
 */
class InventoryMasterLifecycleTest extends TestCase
{
    use CreatesCompanyUser;
    use RefreshDatabase;

    private User $admin;

    private Location $warehouse;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->createCompanyUser();
        $this->warehouse = Location::factory()->create(['company_id' => $this->admin->company_id, 'code' => 'WH-LC', 'name' => 'Gudang']);
        $this->actingAs($this->admin);
    }

    /** @return array<string, array{string}> the route base of each master */
    public static function masters(): array
    {
        return ['product' => ['products'], 'category' => ['categories'], 'unit' => ['units']];
    }

    /** @return array<string, array{class-string, string}> */
    public static function historyRelations(): array
    {
        return [
            'Category::parent' => [Category::class, 'parent'],
            'Category::unit' => [Category::class, 'unit'],
            'Product::category' => [Product::class, 'category'],
            'Product::unit' => [Product::class, 'unit'],
            'Stock::product' => [Stock::class, 'product'],
            'Stock::location' => [Stock::class, 'location'],
            'StockMovement::product' => [StockMovement::class, 'product'],
            'StockMovement::fromLocation' => [StockMovement::class, 'fromLocation'],
            'StockMovement::toLocation' => [StockMovement::class, 'toLocation'],
            'StockAdjustmentRequest::product' => [StockAdjustmentRequest::class, 'product'],
            'StockAdjustmentRequest::location' => [StockAdjustmentRequest::class, 'location'],
            'InventoryRequest::warehouseLocation' => [InventoryRequest::class, 'warehouseLocation'],
            'InventoryRequestItem::product' => [InventoryRequestItem::class, 'product'],
            'InventoryRequestItem::sourceLocation' => [InventoryRequestItem::class, 'sourceLocation'],
            'MaterialReturnCase::workOrder' => [MaterialReturnCase::class, 'workOrder'],
            'MaterialReturnCase::warehouseLocation' => [MaterialReturnCase::class, 'warehouseLocation'],
            'MaterialReturnLine::product' => [MaterialReturnLine::class, 'product'],
        ];
    }

    public function test_a_product_with_stock_movements_is_deactivated_instead_of_deleted(): void
    {
        $product = $this->product();
        $this->movement($product);

        $this->deleteJson(route('admin.products.destroy', $product))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['lifecycle' => 'Barang masih dipakai 1 mutasi stok. Nonaktifkan saja.']);
        $this->assertDatabaseHas('products', ['id' => $product->id, 'deleted_at' => null]);

        $this->from('/back')->post(route('admin.products.deactivate', $product->public_id))
            ->assertRedirect('/back')->assertSessionHas('success', 'Barang dinonaktifkan.');
        $this->assertFalse($product->fresh()->is_active);
        $this->assertDatabaseHas('activity_log', ['log_name' => 'product', 'description' => 'deactivated']);

        $this->from('/back')->post(route('admin.products.activate', $product->public_id))
            ->assertSessionHas('success', 'Barang diaktifkan.');
        $this->assertTrue($product->fresh()->is_active);
    }

    public function test_a_product_that_nothing_points_at_is_deleted_for_good_with_its_empty_stock_rows(): void
    {
        $product = $this->product();
        Stock::query()->create(['product_id' => $product->id, 'location_id' => $this->warehouse->id, 'quantity' => 0, 'reserved_quantity' => 0]);

        $this->delete(route('admin.products.destroy', $product))->assertSessionHas('success', 'Barang dihapus permanen.');

        $this->assertDatabaseMissing('products', ['id' => $product->id]);
        $this->assertDatabaseMissing('stocks', ['product_id' => $product->id]);
        $this->assertDatabaseHas('activity_log', ['log_name' => 'product', 'description' => 'force_deleted']);
    }

    public function test_a_product_that_still_holds_stock_cannot_be_deleted(): void
    {
        $product = $this->product();
        Stock::query()->create(['product_id' => $product->id, 'location_id' => $this->warehouse->id, 'quantity' => 5, 'reserved_quantity' => 0]);

        $this->deleteJson(route('admin.products.destroy', $product))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['lifecycle' => 'Barang masih punya stok.']);

        $this->assertDatabaseHas('products', ['id' => $product->id, 'deleted_at' => null]);
        $this->assertDatabaseHas('stocks', ['product_id' => $product->id, 'quantity' => 5]);
    }

    public function test_a_product_on_an_spk_item_or_an_invoice_line_can_only_be_deactivated_even_after_that_record_is_deleted(): void
    {
        $onSpk = $this->product(['name' => 'Di SPK']);
        $onInvoice = $this->product(['name' => 'Di tagihan']);
        $company = $this->admin->company_id;
        $workOrder = WorkOrderFactory::new()->create(['company_id' => $company]);
        WorkOrderItem::query()->create(['company_id' => $company, 'work_order_id' => $workOrder->id, 'product_id' => $onSpk->id, 'quantity_used' => 1]);
        $invoice = InvoiceFactory::new()->create(['company_id' => $company, 'status' => 'draft']);
        InvoiceItem::query()->create(['company_id' => $company, 'invoice_id' => $invoice->id, 'product_id' => $onInvoice->id, 'description' => 'Kabel', 'quantity' => 1, 'unit_price' => 1000, 'line_total' => 1000]);
        $workOrder->delete();
        $invoice->delete();

        $this->deleteJson(route('admin.products.destroy', $onSpk))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['lifecycle' => 'Barang masih dipakai 1 item SPK. Nonaktifkan saja.']);
        $this->deleteJson(route('admin.products.destroy', $onInvoice))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['lifecycle' => 'Barang masih dipakai 1 item tagihan. Nonaktifkan saja.']);

        $this->assertDatabaseHas('products', ['id' => $onSpk->id, 'deleted_at' => null]);
        $this->assertDatabaseHas('products', ['id' => $onInvoice->id, 'deleted_at' => null]);
    }

    public function test_the_product_index_lists_what_points_at_each_product_and_ignores_the_old_is_active_filter(): void
    {
        $live = $this->product(['name' => 'Aktif']);
        $this->product(['name' => 'Mati', 'is_active' => false]);
        $this->product(['name' => 'Hilang'])->delete();
        $this->movement($live);
        $this->movement($live);
        StockAdjustmentRequest::query()->create([
            'company_id' => $this->admin->company_id,
            'product_id' => $live->id,
            'location_id' => $this->warehouse->id,
            'old_quantity' => 5,
            'new_quantity' => 3,
            'delta' => -2,
            'reason' => 'Rusak',
            'requested_by' => $this->admin->id,
        ]);

        $props = $this->props($this->get(route('admin.products.index', ['is_active' => '0', 'status' => 'semua', 'sort' => 'name', 'direction' => 'asc'])));
        $rows = collect($props['products']['data'])->keyBy('name');

        $this->assertSame(['Aktif', 'Hilang', 'Mati'], $rows->keys()->all(), 'is_active is no longer a filter');
        $this->assertSame(['status' => 'semua', 'sort' => 'name', 'direction' => 'asc'], $props['filters']);
        $this->assertSame([
            ['label' => 'mutasi stok', 'count' => 2],
            ['label' => 'permintaan barang', 'count' => 0],
            ['label' => 'retur material', 'count' => 0],
            ['label' => 'penyesuaian stok', 'count' => 1],
            ['label' => 'item SPK', 'count' => 0],
            ['label' => 'aset jaringan', 'count' => 0],
            ['label' => 'item tagihan', 'count' => 0],
        ], $rows['Aktif']['references']);
        $this->assertSame(0, array_sum(array_column($rows['Mati']['references'], 'count')));
        $this->assertNull($rows['Aktif']['deleted_at']);
        $this->assertNotNull($rows['Hilang']['deleted_at']);
        $this->assertSame(['create' => true, 'export' => true, 'update' => true, 'delete' => true], $props['can']);
    }

    #[DataProvider('masters')]
    public function test_the_status_filter_values_on_every_index(string $base): void
    {
        $this->master($base, 'Aktif');
        $this->master($base, 'Mati', ['is_active' => false]);
        $this->master($base, 'Hilang')->delete();

        $this->assertSame(['Aktif', 'Mati'], $this->listed($base), 'the default view hides what was deleted');
        $this->assertSame(['Aktif', 'Mati'], $this->listed($base, 'bogus'), 'an unknown value is the default view');
        $this->assertSame(['Aktif'], $this->listed($base, 'aktif'));
        $this->assertSame(['Mati'], $this->listed($base, 'nonaktif'));
        $this->assertSame(['Hilang'], $this->listed($base, 'dihapus'));
        $this->assertSame(['Aktif', 'Hilang', 'Mati'], $this->listed($base, 'semua'));
    }

    public function test_a_category_counts_its_children_and_products_and_is_deleted_only_when_none_remain(): void
    {
        $parent = $this->category(['name' => 'Induk', 'code' => 'PRN']);
        $this->category(['name' => 'Anak hidup', 'code' => 'ANK-1', 'parent_id' => $parent->id]);
        $this->category(['name' => 'Anak hilang', 'code' => 'ANK-2', 'parent_id' => $parent->id])->delete();
        $this->product(['category_id' => $parent->id]);
        $leaf = $this->category(['name' => 'Daun', 'code' => 'LEAF']);

        $rows = collect($this->props($this->get(route('admin.categories.index')))['categories']['data'])->keyBy('name');

        $this->assertSame([['label' => 'sub-kategori', 'count' => 2], ['label' => 'barang', 'count' => 1]], $rows['Induk']['references'], 'a deleted child still counts');
        $this->assertSame(1, $rows['Induk']['children_count'], 'the Sub-kategori column still shows the live children');
        $this->assertSame([['label' => 'sub-kategori', 'count' => 0], ['label' => 'barang', 'count' => 0]], $rows['Daun']['references']);

        $this->deleteJson(route('admin.categories.destroy', $parent))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['lifecycle' => 'Kategori barang masih dipakai 2 sub-kategori, 1 barang. Nonaktifkan saja.']);
        $this->assertDatabaseHas('categories', ['id' => $parent->id, 'deleted_at' => null]);

        $this->delete(route('admin.categories.destroy', $leaf))->assertSessionHas('success', 'Kategori barang dihapus permanen.');
        $this->assertDatabaseMissing('categories', ['id' => $leaf->id]);
    }

    public function test_a_deleted_category_comes_back_only_after_its_parent(): void
    {
        $parent = $this->category(['name' => 'Induk lama', 'code' => 'OLD-P']);
        $child = $this->category(['name' => 'Anak lama', 'code' => 'OLD-C', 'parent_id' => $parent->id]);
        $child->delete();
        $parent->delete();

        $this->postJson(route('admin.categories.restore', $child->public_id))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['lifecycle' => 'Kategori barang tidak bisa dipulihkan karena induknya, Induk lama, masih dihapus. Pulihkan induknya dulu.']);
        $this->assertSoftDeleted($child);

        $this->post(route('admin.categories.restore', $parent->public_id))->assertSessionHas('success', 'Kategori barang dipulihkan.');
        $this->post(route('admin.categories.restore', $child->public_id))->assertSessionHas('success', 'Kategori barang dipulihkan.');
        $this->assertNotSoftDeleted($child);
    }

    public function test_a_unit_in_use_is_deactivated_instead_of_deleted_and_leaves_the_pickers(): void
    {
        $roll = $this->unit(['name' => 'Roll', 'symbol' => 'rl']);
        $this->unit(['name' => 'Meter', 'symbol' => 'm']);
        $cable = $this->category(['name' => 'Kabel', 'code' => 'KBL', 'unit_id' => $roll->id]);
        $this->product(['category_id' => $cable->id]);

        $rows = collect($this->props($this->get(route('admin.units.index')))['units']['data'])->keyBy('name');
        $this->assertSame([['label' => 'barang', 'count' => 1], ['label' => 'kategori barang', 'count' => 1]], $rows['Roll']['references']);
        $this->assertTrue($rows['Roll']['is_active']);

        $this->deleteJson(route('admin.units.destroy', $roll))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['lifecycle' => 'Satuan masih dipakai 1 barang, 1 kategori barang. Nonaktifkan saja.']);
        $this->assertDatabaseHas('units', ['id' => $roll->id, 'deleted_at' => null]);

        $this->from('/back')->post(route('admin.units.deactivate', $roll->public_id))
            ->assertRedirect('/back')->assertSessionHas('success', 'Satuan dinonaktifkan.');
        $this->assertFalse($roll->fresh()->is_active);
        $this->assertSame(['Meter'], $this->listed('units', 'aktif'));
        $this->assertSame(['Roll'], $this->listed('units', 'nonaktif'));

        $categories = $this->props($this->get(route('admin.categories.index')));
        $this->assertSame(['Meter'], array_column($categories['units']['data'], 'name'), 'the picker offers active units only');
        $held = collect($categories['categories']['data'])->firstWhere('name', 'Kabel');
        $this->assertSame(['Roll', false], [$held['unit']['name'], $held['unit']['is_active']], 'the category row still carries its own unit for the edit form');

        $this->postJson(route('admin.categories.store'), ['name' => 'Baru', 'code' => 'NEW', 'unit_id' => $roll->public_id, 'is_active' => true])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['unit_id' => 'Satuan yang dipilih tidak aktif atau tidak ditemukan.']);
        $this->putJson(route('admin.categories.update', $cable), ['name' => 'Kabel serat', 'code' => 'KBL', 'unit_id' => $roll->public_id, 'is_active' => true])->assertRedirect();
        $this->assertSame('Kabel serat', $cable->fresh()->name, 'a category keeps the inactive unit it already has');

        $this->from('/back')->post(route('admin.units.activate', $roll->public_id))->assertSessionHas('success', 'Satuan diaktifkan.');
        $this->assertSame(['Meter', 'Roll'], collect($this->props($this->get(route('admin.categories.index')))['units']['data'])->pluck('name')->sort()->values()->all());
    }

    public function test_an_unused_unit_is_deleted_for_good(): void
    {
        $free = $this->unit(['name' => 'Pak', 'symbol' => 'pk']);

        $this->delete(route('admin.units.destroy', $free))->assertSessionHas('success', 'Satuan dihapus permanen.');

        $this->assertDatabaseMissing('units', ['id' => $free->id]);
    }

    public function test_the_product_forms_offer_the_categories_whose_unit_is_active_and_the_products_own(): void
    {
        $meter = $this->unit(['name' => 'Meter', 'symbol' => 'm']);
        $roll = $this->unit(['name' => 'Roll', 'symbol' => 'rl', 'is_active' => false]);
        $cable = $this->category(['name' => 'Kabel', 'code' => 'KBL', 'unit_id' => $meter->id]);
        $drum = $this->category(['name' => 'Gulungan', 'code' => 'GLG', 'unit_id' => $roll->id]);
        $this->category(['name' => 'Tutup', 'code' => 'TTP', 'unit_id' => $meter->id, 'is_active' => false]);
        $held = $this->product(['name' => 'Lama', 'category_id' => $drum->id]);
        $fresh = $this->product(['name' => 'Baru', 'category_id' => $cable->id]);

        $offered = fn (string $route, array $parameters = []): array => collect($this->props($this->get(route($route, $parameters)))['categories']['data'])->pluck('name')->sort()->values()->all();

        $this->assertSame(['Kabel'], $offered('admin.products.create'));
        $this->assertSame(['Gulungan', 'Kabel'], $offered('admin.products.edit', [$held]), 'the product keeps its own category');
        $this->assertSame(['Kabel'], $offered('admin.products.edit', [$fresh]));

        $this->postJson(route('admin.products.store'), ['name' => 'Gulungan baru', 'category_id' => $drum->public_id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['unit_id' => 'Satuan kategori ini sudah tidak aktif. Ganti satuan kategori terlebih dahulu.']);

        $payload = fn (Product $product, Category $category, string $name): array => [
            'sku' => $product->sku,
            'name' => $name,
            'category_id' => $category->public_id,
            OptimisticLock::FIELD => OptimisticLock::version($product->fresh()),
        ];

        $this->putJson(route('admin.products.update', $held), $payload($held, $drum, 'Lama diubah'))->assertRedirect(route('admin.products.index'));
        $this->assertSame('Lama diubah', $held->fresh()->name, 'a product keeps the inactive unit it already has');

        $this->putJson(route('admin.products.update', $fresh), $payload($fresh, $drum, 'Pindah'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['unit_id' => 'Satuan kategori ini sudah tidak aktif. Ganti satuan kategori terlebih dahulu.']);
        $this->assertSame($cable->id, $fresh->fresh()->category_id);
    }

    #[DataProvider('masters')]
    public function test_a_deleted_master_is_listed_under_dihapus_and_comes_back_with_pulihkan(string $base): void
    {
        $record = $this->master($base, 'Lama');
        $record->delete();

        $row = $this->props($this->get(route("admin.{$base}.index", ['status' => 'dihapus'])))[$base]['data'][0];
        $this->assertSame($record->public_id, $row['id']);
        $this->assertNotNull($row['deleted_at']);

        $this->from('/back')->post(route("admin.{$base}.restore", $record->public_id))
            ->assertRedirect('/back')->assertSessionHas('success', $record::lifecycleNoun().' dipulihkan.');

        $this->assertNotSoftDeleted($record);
        $this->assertSame(['Lama'], $this->listed($base));
        $this->assertSame([], $this->listed($base, 'dihapus'));
        $this->assertDatabaseHas('activity_log', ['log_name' => Str::singular($base), 'description' => 'restored']);
    }

    #[DataProvider('masters')]
    public function test_deactivate_needs_the_update_permission_and_restore_and_destroy_the_delete_permission(string $base): void
    {
        $record = $this->master($base, 'Rekaman');
        $updater = $this->user('stock-updater', ['inventory.view', 'inventory.update']);
        $deleter = $this->user('stock-deleter', ['inventory.view', 'inventory.delete']);

        $this->actingAs($deleter)->postJson(route("admin.{$base}.deactivate", $record->public_id))->assertForbidden();
        $this->actingAs($updater)->deleteJson(route("admin.{$base}.destroy", $record->public_id))->assertForbidden();
        $this->assertTrue($record->fresh()->is_active);

        $this->actingAs($updater)->postJson(route("admin.{$base}.deactivate", $record->public_id))->assertRedirect();
        $this->assertFalse($record->fresh()->is_active);

        $record->delete();
        $this->actingAs($updater)->postJson(route("admin.{$base}.restore", $record->public_id))->assertForbidden();
        $this->assertSoftDeleted($record);

        $this->actingAs($deleter)->postJson(route("admin.{$base}.restore", $record->public_id))->assertRedirect();
        $this->assertNotSoftDeleted($record);
    }

    #[DataProvider('masters')]
    public function test_another_company_record_is_not_reachable_through_the_lifecycle_routes(string $base): void
    {
        $other = Company::factory()->create(['is_active' => true]);
        $foreign = match ($base) {
            'products' => Product::factory()->create(['company_id' => $other->id]),
            'categories' => Category::factory()->create(['company_id' => $other->id]),
            'units' => Unit::factory()->create(['company_id' => $other->id]),
        };

        foreach (['deactivate', 'activate', 'restore'] as $action) {
            $this->postJson(route("admin.{$base}.{$action}", $foreign->public_id))->assertNotFound();
        }
        $this->deleteJson(route("admin.{$base}.destroy", $foreign->public_id))->assertNotFound();
        $this->assertDatabaseHas($foreign->getTable(), ['id' => $foreign->id, 'deleted_at' => null]);
    }

    public function test_a_deleted_row_still_reserves_its_code_or_name(): void
    {
        $this->category(['name' => 'Lama', 'code' => 'RSV'])->delete();
        $this->product(['name' => 'Lama', 'sku' => 'RSV-1'])->delete();
        $this->unit(['name' => 'Lama', 'symbol' => 'rsv'])->delete();
        $unit = $this->unit(['name' => 'Baru', 'symbol' => 'br']);

        $this->postJson(route('admin.categories.store'), ['name' => 'Kategori', 'code' => 'RSV', 'unit_id' => $unit->public_id, 'is_active' => true])
            ->assertUnprocessable()->assertJsonValidationErrors(['code']);
        $this->postJson(route('admin.products.store'), ['name' => 'Barang', 'sku' => 'RSV-1', 'category_id' => $this->category(['unit_id' => $unit->id])->public_id])
            ->assertUnprocessable()->assertJsonValidationErrors(['sku']);
        $this->postJson(route('admin.units.store'), ['name' => 'Lama', 'symbol' => 'x'])
            ->assertUnprocessable()->assertJsonValidationErrors(['name']);
    }

    #[DataProvider('historyRelations')]
    public function test_history_relations_include_soft_deleted_parents(string $model, string $relation): void
    {
        $removed = (new $model())->{$relation}()->getQuery()->removedScopes();

        $this->assertContains(SoftDeletingScope::class, $removed, "{$model}::{$relation}() hides a deleted parent");
    }

    public function test_a_ledger_row_still_shows_the_product_and_location_that_were_deleted(): void
    {
        $product = $this->product(['name' => 'Kabel lama']);
        $movement = $this->movement($product);
        $product->delete();
        $this->warehouse->delete();

        $row = StockMovement::query()->with(['product', 'toLocation'])->findOrFail($movement->id);

        $this->assertSame('Kabel lama', $row->product?->name);
        $this->assertSame($this->warehouse->id, $row->toLocation?->id);
    }

    /** @param  array<string, mixed>  $attributes */
    private function unit(array $attributes = []): Unit
    {
        return Unit::factory()->create(['company_id' => $this->admin->company_id, ...$attributes]);
    }

    /** @param  array<string, mixed>  $attributes */
    private function category(array $attributes = []): Category
    {
        return Category::factory()->create(['company_id' => $this->admin->company_id, ...$attributes]);
    }

    /** @param  array<string, mixed>  $attributes */
    private function product(array $attributes = []): Product
    {
        return Product::factory()->create(['company_id' => $this->admin->company_id, ...$attributes]);
    }

    private function movement(Product $product): StockMovement
    {
        return StockMovement::query()->create([
            'product_id' => $product->id,
            'to_location_id' => $this->warehouse->id,
            'movement_type' => 'receive',
            'quantity' => 1,
            'balance_after' => 1,
            'reserved_after' => 0,
            'created_by' => $this->admin->id,
        ]);
    }

    /** @param  array<string, mixed>  $attributes */
    private function master(string $base, string $name, array $attributes = []): Product|Category|Unit
    {
        $key = Str::slug($name);

        return match ($base) {
            'products' => $this->product(['name' => $name, 'sku' => 'SKU-'.$key, ...$attributes]),
            'categories' => $this->category(['name' => $name, 'code' => $key, ...$attributes]),
            'units' => $this->unit(['name' => $name, 'symbol' => $key, ...$attributes]),
        };
    }

    /** @param  list<string>  $permissions */
    private function user(string $role, array $permissions): User
    {
        $record = Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        $record->syncPermissions($permissions);

        return User::factory()->create(['company_id' => $this->admin->company_id, 'email_verified_at' => now(), 'is_active' => true])->assignRole($record);
    }

    /** @return list<string> the names an index lists for a status, sorted */
    private function listed(string $base, ?string $status = null): array
    {
        $query = ['sort' => 'name', 'direction' => 'asc', 'is_active' => '0', ...($status === null ? [] : ['status' => $status])];

        return array_column($this->props($this->actingAs($this->admin)->get(route("admin.{$base}.index", $query))->assertOk())[$base]['data'], 'name');
    }

    /** @return array<string, mixed> */
    private function props(TestResponse $response): array
    {
        return $response->viewData('page')['props'];
    }
}
