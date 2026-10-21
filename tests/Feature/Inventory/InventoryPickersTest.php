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
use Modules\SPK\Database\Factories\WorkOrderFactory;
use Modules\SPK\Models\WorkOrder;
use Modules\Ticketing\Models\Ticket;
use Modules\Ticketing\Models\TicketCategory;
use Tests\TestCase;
use Tests\Traits\WithoutAppOnlyRedirect;

/**
 * The record pickers of the inventory pages (`productOptions`, `locationOptions`, `workOrderOptions`, `ticketOptions`
 * and the `selected*` labels), the defects fixed with them, and the Indonesian export headings.
 */
class InventoryPickersTest extends TestCase
{
    use RefreshDatabase;
    use WithoutAppOnlyRedirect;

    private Company $company;

    private Company $other;

    private User $admin;

    private User $technician;

    private Location $warehouse;

    private Product $cable;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->company = Company::factory()->create(['is_active' => true]);
        $this->other = Company::factory()->create(['is_active' => true]);
        $this->admin = $this->member('admin', 'admin@pickers.test');
        $this->technician = $this->member('technician', 'tech@pickers.test');

        $unit = Unit::factory()->create(['company_id' => $this->company->id, 'name' => 'Meter', 'symbol' => 'm']);
        $category = Category::factory()->create(['company_id' => $this->company->id, 'unit_id' => $unit->id, 'code' => 'KBL', 'name' => 'Kabel']);
        $this->cable = Product::factory()->create([
            'company_id' => $this->company->id,
            'category_id' => $category->id,
            'unit_id' => $unit->id,
            'sku' => 'KBL-001',
            'name' => 'Kabel Dropcore',
        ]);
        $this->warehouse = Location::factory()->create([
            'company_id' => $this->company->id,
            'code' => 'WH-PUSAT',
            'name' => 'Gudang Pusat',
            'path' => 'Bandung / Gudang Pusat',
        ]);
    }

    public function test_the_stock_page_sends_no_catalogue_and_labels_the_filters_it_holds(): void
    {
        $this->actingAs($this->admin)->get(route('admin.stocks.index'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->missing('products')->missing('locations')
                ->missing('productOptions')->missing('locationOptions')
                ->where('selectedProduct', null)->where('selectedLocation', null));

        $this->get(route('admin.stocks.index', ['product_id' => $this->cable->public_id, 'location_id' => $this->warehouse->public_id]))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('selectedProduct', ['value' => $this->cable->public_id, 'label' => 'KBL-001 - Kabel Dropcore (m)', 'description' => 'Kabel'])
                ->where('selectedLocation', ['value' => $this->warehouse->public_id, 'label' => 'WH-PUSAT - Gudang Pusat', 'description' => 'Bandung / Gudang Pusat']));
    }

    public function test_a_filter_naming_another_companys_record_gets_no_label(): void
    {
        $foreignProduct = Product::factory()->create(['company_id' => $this->other->id]);
        $foreignLocation = Location::factory()->create(['company_id' => $this->other->id]);

        foreach (['admin.stocks.index', 'admin.stock-movements.index'] as $route) {
            $this->actingAs($this->admin)->get(route($route, ['product_id' => $foreignProduct->public_id, 'location_id' => $foreignLocation->public_id]))->assertOk()
                ->assertInertia(fn (Assert $page) => $page->where('selectedProduct', null)->where('selectedLocation', null));
        }
    }

    public function test_the_movements_page_carries_the_same_pickers_and_labels(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.stock-movements.index', ['product_id' => $this->cable->public_id, 'location_id' => $this->warehouse->public_id]))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->missing('products')->missing('locations')->missing('productOptions')
                ->where('selectedProduct.label', 'KBL-001 - Kabel Dropcore (m)')
                ->where('selectedLocation.label', 'WH-PUSAT - Gudang Pusat'));

        $options = $this->partial(route('admin.stock-movements.index', ['product_search' => 'kabel']), 'Admin/Inventory/Movements/Index', 'productOptions');
        $this->assertSame([$this->cable->public_id], array_column($options->json('props.productOptions'), 'value'));
    }

    public function test_the_product_picker_offers_active_products_of_the_company_from_two_characters(): void
    {
        $this->actingAs($this->admin);
        Product::factory()->create(['company_id' => $this->company->id, 'name' => 'Kabel Lama', 'is_active' => false]);
        Product::factory()->create(['company_id' => $this->other->id, 'name' => 'Kabel Asing']);

        $found = $this->partial(route('admin.stocks.index', ['product_search' => 'kabel']), 'Admin/Inventory/Stocks/Index', 'productOptions')->assertOk();
        $this->assertSame(
            [['value' => $this->cable->public_id, 'label' => 'KBL-001 - Kabel Dropcore (m)', 'description' => 'Kabel']],
            $found->json('props.productOptions'),
        );

        $bySku = $this->partial(route('admin.stocks.index', ['product_search' => 'KBL-00']), 'Admin/Inventory/Stocks/Index', 'productOptions');
        $this->assertSame([$this->cable->public_id], array_column($bySku->json('props.productOptions'), 'value'));

        $short = $this->partial(route('admin.stocks.index', ['product_search' => 'k']), 'Admin/Inventory/Stocks/Index', 'productOptions');
        $this->assertSame([], $short->json('props.productOptions'));
    }

    public function test_the_location_picker_offers_active_locations_of_the_company(): void
    {
        $this->actingAs($this->admin);
        Location::factory()->create(['company_id' => $this->company->id, 'name' => 'Gudang Tutup', 'is_active' => false]);
        Location::factory()->create(['company_id' => $this->other->id, 'name' => 'Gudang Asing']);

        $found = $this->partial(route('admin.stocks.index', ['location_search' => 'gudang']), 'Admin/Inventory/Stocks/Index', 'locationOptions')->assertOk();

        $this->assertSame(
            [['value' => $this->warehouse->public_id, 'label' => 'WH-PUSAT - Gudang Pusat', 'description' => 'Bandung / Gudang Pusat']],
            $found->json('props.locationOptions'),
        );
    }

    public function test_the_request_form_sends_warehouses_and_a_product_picker_without_assets(): void
    {
        Product::factory()->create(['company_id' => $this->company->id, 'sku' => 'KBL-AST', 'name' => 'Kabel Router Aset', 'type' => 'asset']);

        $this->actingAs($this->technician)->get(route('admin.inventory.requests.create'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('warehouses', 1)
                ->where('warehouses.0.id', $this->warehouse->public_id)
                ->missing('products')->missing('productOptions'));

        $found = $this->partial(route('admin.inventory.requests.create', ['product_search' => 'kabel']), 'Admin/Inventory/Requests/Create', 'productOptions');
        $this->assertSame([$this->cable->public_id], array_column($found->json('props.productOptions'), 'value'));
    }

    public function test_the_source_pickers_offer_what_the_person_may_already_open(): void
    {
        $mine = $this->workOrder('SPK-MINE-1', $this->technician->id);
        $theirs = $this->workOrder('SPK-THEIRS-1', $this->admin->id);
        $this->workOrder('SPK-FOREIGN-1', null, $this->other);
        $ownTicket = $this->ticket('TCK-MINE-1', $this->technician->id);
        $otherTicket = $this->ticket('TCK-THEIRS-1', $this->admin->id);
        $this->ticket('TCK-FOREIGN-1', null, $this->other);

        $asTechnician = fn (string $field, string $prop, string $search) => array_column(
            $this->partial(route('admin.inventory.requests.create', [$field => $search]), 'Admin/Inventory/Requests/Create', $prop)->json('props.'.$prop),
            'value',
        );

        $this->actingAs($this->technician);
        $this->assertSame([$mine->public_id], $asTechnician('workOrder_search', 'workOrderOptions', 'SPK-'));
        $this->assertSame([$ownTicket->public_id], $asTechnician('ticket_search', 'ticketOptions', 'TCK-'));

        $this->actingAs($this->admin);
        $this->assertSame([$theirs->public_id, $mine->public_id], $asTechnician('workOrder_search', 'workOrderOptions', 'SPK-'));
        $this->assertSame([$otherTicket->public_id, $ownTicket->public_id], $asTechnician('ticket_search', 'ticketOptions', 'TCK-'));
    }

    public function test_a_person_who_cannot_view_spk_or_tickets_is_offered_none(): void
    {
        $this->workOrder('SPK-MINE-1', $this->technician->id);
        $this->ticket('TCK-MINE-1', $this->technician->id);
        $requester = User::factory()->create(['company_id' => $this->company->id, 'email_verified_at' => now(), 'is_active' => true]);
        $requester->givePermissionTo('inventory.request.create');

        $this->actingAs($requester);
        $this->assertSame([], $this->partial(route('admin.inventory.requests.create', ['workOrder_search' => 'SPK-']), 'Admin/Inventory/Requests/Create', 'workOrderOptions')->json('props.workOrderOptions'));
        $this->assertSame([], $this->partial(route('admin.inventory.requests.create', ['ticket_search' => 'TCK-']), 'Admin/Inventory/Requests/Create', 'ticketOptions')->json('props.ticketOptions'));
    }

    public function test_a_needed_date_must_be_a_real_day_and_is_shown_as_that_day(): void
    {
        $this->actingAs($this->technician);

        $this->postJson(route('admin.inventory.requests.store'), $this->requestPayload(['needed_at' => '20/10/2026']))
            ->assertStatus(422)
            ->assertJsonPath('errors.needed_at.0', 'Tanggal dibutuhkan harus berupa tanggal yang valid.');

        $this->postJson(route('admin.inventory.requests.store'), $this->requestPayload(['needed_at' => '2026-10-20']))
            ->assertStatus(201)
            ->assertJsonPath('data.needed_at', '2026-10-20');
    }

    public function test_a_source_must_belong_to_the_company_and_is_required_with_its_type(): void
    {
        $foreignWorkOrder = $this->workOrder('SPK-FOREIGN-1', null, $this->other);
        $foreignTicket = $this->ticket('TCK-FOREIGN-1', null, $this->other);
        $this->actingAs($this->technician);

        $this->postJson(route('admin.inventory.requests.store'), $this->requestPayload(['source_type' => 'work_order', 'source_id' => $foreignWorkOrder->public_id]))
            ->assertStatus(422)
            ->assertJsonPath('errors.source_id.0', 'SPK yang dipilih tidak valid untuk perusahaan ini.');

        $this->postJson(route('admin.inventory.requests.store'), $this->requestPayload(['source_type' => 'ticket', 'source_id' => $foreignTicket->public_id]))
            ->assertStatus(422)
            ->assertJsonPath('errors.source_id.0', 'Tiket yang dipilih tidak valid untuk perusahaan ini.');

        $this->postJson(route('admin.inventory.requests.store'), $this->requestPayload(['source_type' => 'ticket']))
            ->assertStatus(422)
            ->assertJsonPath('errors.source_id.0', 'rujukan wajib diisi jika jenis rujukan terisi.');
    }

    public function test_a_source_must_be_a_record_the_person_may_already_open(): void
    {
        $mine = $this->workOrder('SPK-MINE-1', $this->technician->id);
        $theirs = $this->workOrder('SPK-THEIRS-1', $this->admin->id);
        $ownTicket = $this->ticket('TCK-MINE-1', $this->technician->id);
        $otherTicket = $this->ticket('TCK-THEIRS-1', $this->admin->id);

        $this->actingAs($this->technician);

        $this->postJson(route('admin.inventory.requests.store'), $this->requestPayload(['source_type' => 'work_order', 'source_id' => $theirs->public_id]))
            ->assertStatus(422)
            ->assertJsonPath('errors.source_id.0', 'SPK yang dipilih tidak valid untuk perusahaan ini.');
        $this->postJson(route('admin.inventory.requests.store'), $this->requestPayload(['source_type' => 'ticket', 'source_id' => $otherTicket->public_id]))
            ->assertStatus(422)
            ->assertJsonPath('errors.source_id.0', 'Tiket yang dipilih tidak valid untuk perusahaan ini.');

        $this->postJson(route('admin.inventory.requests.store'), $this->requestPayload(['source_type' => 'work_order', 'source_id' => $mine->public_id]))
            ->assertStatus(201)
            ->assertJsonPath('data.source_id', $mine->public_id);
        $this->postJson(route('admin.inventory.requests.store'), $this->requestPayload(['source_type' => 'ticket', 'source_id' => $ownTicket->public_id]))
            ->assertStatus(201)
            ->assertJsonPath('data.source_id', $ownTicket->public_id);

        // Someone who assigns work sees every record of the company, so any of them may be named.
        $this->actingAs($this->admin);

        $this->postJson(route('admin.inventory.requests.store'), $this->requestPayload(['source_type' => 'work_order', 'source_id' => $mine->public_id]))
            ->assertStatus(201);
        $this->postJson(route('admin.inventory.requests.store'), $this->requestPayload(['source_type' => 'ticket', 'source_id' => $ownTicket->public_id]))
            ->assertStatus(201);
    }

    public function test_a_person_who_cannot_view_spk_or_tickets_cannot_name_one_as_the_source(): void
    {
        $workOrder = $this->workOrder('SPK-OPEN-1', $this->technician->id);
        $ticket = $this->ticket('TCK-OPEN-1', $this->technician->id);
        $requester = User::factory()->create(['company_id' => $this->company->id, 'email_verified_at' => now(), 'is_active' => true]);
        $requester->givePermissionTo('inventory.request.create');

        $this->actingAs($requester);

        $this->postJson(route('admin.inventory.requests.store'), $this->requestPayload(['source_type' => 'work_order', 'source_id' => $workOrder->public_id]))
            ->assertStatus(422)
            ->assertJsonPath('errors.source_id.0', 'SPK yang dipilih tidak valid untuk perusahaan ini.');
        $this->postJson(route('admin.inventory.requests.store'), $this->requestPayload(['source_type' => 'ticket', 'source_id' => $ticket->public_id]))
            ->assertStatus(422)
            ->assertJsonPath('errors.source_id.0', 'Tiket yang dipilih tidak valid untuk perusahaan ini.');
    }

    public function test_the_category_page_offers_every_category_as_a_parent(): void
    {
        $odp = Category::factory()->create(['company_id' => $this->company->id, 'code' => 'ODP', 'name' => 'Perangkat ODP']);
        Category::factory()->create(['company_id' => $this->other->id, 'code' => 'ASG', 'name' => 'Asing']);

        $this->actingAs($this->admin)->get(route('admin.categories.index', ['per_page' => 10]))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('categoryOptions', 2)
                ->where('categoryOptions.0.code', 'KBL')
                ->where('categoryOptions.0.id', $this->cable->category->public_id)
                ->where('categoryOptions.1.code', 'ODP')
                ->where('categoryOptions.1.id', $odp->public_id));
    }

    public function test_the_product_page_lists_its_recent_movements_newest_first(): void
    {
        $this->actingAs($this->admin);
        $this->movement($this->cable, 'receive', 10, 'masuk pertama');
        $this->movement($this->cable, 'issue', 3, 'keluar kedua');

        $this->get(route('admin.products.show', $this->cable))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('product.data.movements', 2)
                ->where('product.data.movements.0.note', 'keluar kedua')
                ->where('product.data.movements.0.movement_type', 'issue')
                ->where('product.data.movements.1.note', 'masuk pertama'));
    }

    public function test_a_unit_that_a_category_uses_cannot_be_deleted(): void
    {
        $this->actingAs($this->admin);
        $used = Unit::factory()->create(['company_id' => $this->company->id, 'name' => 'Roll']);
        Category::factory()->create(['company_id' => $this->company->id, 'unit_id' => $used->id]);
        $free = Unit::factory()->create(['company_id' => $this->company->id, 'name' => 'Pak']);

        $this->delete(route('admin.units.destroy', $used))
            ->assertSessionHasErrors(['lifecycle' => 'Satuan masih dipakai 1 kategori barang. Nonaktifkan saja.']);
        $this->assertNotSoftDeleted($used);

        $this->delete(route('admin.units.destroy', $free))->assertSessionHas('success', 'Satuan dihapus permanen.');
        $this->assertModelMissing($free);
    }

    public function test_the_item_finder_lists_consumables_only(): void
    {
        $this->actingAs($this->admin);
        Product::factory()->create(['company_id' => $this->company->id, 'sku' => 'KBL-AST', 'name' => 'Kabel Router Aset', 'type' => 'asset']);
        Stock::query()->create(['product_id' => $this->cable->id, 'location_id' => $this->warehouse->id, 'quantity' => 12, 'reserved_quantity' => 2]);

        $this->get(route('admin.inventory.find', ['search' => 'Kabel']))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('results', 1)
                ->where('results.0.name', 'Kabel Dropcore')
                ->where('results.0.stocks.0.location_name', 'Gudang Pusat'));
    }

    public function test_exports_carry_indonesian_headings_and_labels(): void
    {
        $this->actingAs($this->admin);
        Stock::query()->create(['product_id' => $this->cable->id, 'location_id' => $this->warehouse->id, 'quantity' => 12, 'reserved_quantity' => 2]);
        $this->movement($this->cable, 'receive', 12, 'penerimaan awal');

        $headings = [
            'admin.stocks.export' => 'Barang,Lokasi,Jumlah,Dicadangkan,Tersedia',
            'admin.stock-movements.export' => 'Jenis,Barang,Dari,Ke,Jumlah,Saldo,Catatan,Tanggal',
            'admin.products.export' => 'SKU,Nama,Kategori,Satuan,"Harga Jual","Harga Pokok","Stok Minimum",Aktif',
            'admin.categories.export' => 'Kode,Nama,Satuan,Sub-kategori',
            'admin.units.export' => 'Nama,Simbol',
        ];

        foreach ($headings as $route => $heading) {
            $csv = $this->get(route($route, ['format' => 'csv']))->assertOk()->streamedContent();
            $this->assertSame($heading, strtok(ltrim($csv, "\xEF\xBB\xBF"), "\r\n"), $route);
        }

        $movements = $this->get(route('admin.stock-movements.export', ['format' => 'csv']))->streamedContent();
        $this->assertStringContainsString('Masuk,"Kabel Dropcore"', $movements);
    }

    /** @return array<string, mixed> */
    private function requestPayload(array $overrides = []): array
    {
        return [
            'warehouse_location_id' => $this->warehouse->public_id,
            'reason' => 'Kebutuhan penarikan kabel pelanggan baru',
            'items' => [['product_id' => $this->cable->public_id, 'quantity_requested' => 5]],
            ...$overrides,
        ];
    }

    private function member(string $role, string $email): User
    {
        $user = User::factory()->create([
            'company_id' => $this->company->id,
            'email' => $email,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
        $this->grantRole($user, $role);

        return $user;
    }

    private function workOrder(string $code, ?int $assignedTo, ?Company $company = null): WorkOrder
    {
        $company ??= $this->company;

        return WorkOrderFactory::new()->create([
            'company_id' => $company->id,
            'code' => $code,
            'title' => 'Pasang '.$code,
            'assigned_to' => $assignedTo,
            'created_by' => $this->admin->id,
        ]);
    }

    private function ticket(string $code, ?int $assignedTo, ?Company $company = null): Ticket
    {
        $company ??= $this->company;
        $category = TicketCategory::query()->create([
            'company_id' => $company->id,
            'name' => 'Gangguan '.$code,
            'code' => 'GGN-'.$code,
            'default_sla_hours' => 2,
            'default_priority' => 'medium',
            'is_active' => true,
        ]);

        return Ticket::factory()->create([
            'company_id' => $company->id,
            'category_id' => $category->id,
            'code' => $code,
            'title' => 'Gangguan '.$code,
            'assigned_to' => $assignedTo,
            'created_by' => $this->admin->id,
        ]);
    }

    private function movement(Product $product, string $type, int $quantity, string $note): StockMovement
    {
        return StockMovement::query()->create([
            'product_id' => $product->id,
            'to_location_id' => $this->warehouse->id,
            'movement_type' => $type,
            'quantity' => $quantity,
            'balance_after' => $quantity,
            'reserved_after' => 0,
            'note' => $note,
            'created_by' => $this->admin->id,
        ]);
    }

    /** A partial Inertia reload of one optional prop, as `RemoteSearchSelect` sends it. */
    private function partial(string $url, string $component, string $prop): TestResponse
    {
        return $this->withHeaders([
            'X-Inertia' => 'true',
            'X-Inertia-Version' => app(HandleInertiaRequests::class)->version(request()),
            'X-Inertia-Partial-Component' => $component,
            'X-Inertia-Partial-Data' => $prop,
        ])->get($url);
    }
}
