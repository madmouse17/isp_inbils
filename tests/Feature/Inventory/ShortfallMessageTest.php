<?php

namespace Tests\Feature\Inventory;

use App\Models\Core\Company;
use App\Models\Core\Location;
use App\Models\User;
use App\Services\Core\CompanyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Modules\Inventory\Exceptions\InsufficientStockException;
use Modules\Inventory\Models\InventoryRequest;
use Modules\Inventory\Models\MaterialReturnCase;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\Stock;
use Modules\Inventory\Models\Unit;
use Modules\Inventory\Services\InventoryRequestService;
use Modules\Inventory\Services\MaterialReturnService;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;
use Tests\Traits\WithoutAppOnlyRedirect;

/**
 * F4-D12: a refusal names what people know, never a code or an opaque id. Approving a request for more than is free used
 * to say "untuk KBL-001 di WH-01. Tersedia: 3, Diminta: 5." and the return checks named the line by its ULID. Now one
 * sentence: "Stok Kabel Drop di Gudang Utama tidak cukup: tersedia 3 m, diminta 5 m."
 */
class ShortfallMessageTest extends TestCase
{
    use RefreshDatabase;
    use WithoutAppOnlyRedirect;

    private const SENTENCE = 'Stok Kabel Drop di Gudang Utama tidak cukup: tersedia 3 m, diminta 5 m.';

    private Company $company;

    private Location $warehouse;

    private Product $product;

    private User $technician;

    private User $approver;

    private User $keeper;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();

        $this->company = Company::factory()->create(['is_active' => true]);
        CompanyService::useCompanyForTests($this->company->id);
        $unit = Unit::factory()->create(['company_id' => $this->company->id, 'name' => 'Meter', 'symbol' => 'm']);
        $this->warehouse = Location::factory()->create(['company_id' => $this->company->id, 'type' => 'site', 'code' => 'WH-01', 'name' => 'Gudang Utama']);
        $this->product = Product::factory()->create(['company_id' => $this->company->id, 'unit_id' => $unit->id, 'sku' => 'KBL-001', 'name' => 'Kabel Drop']);

        $this->technician = $this->person(['inventory.request.create', 'inventory.return.declare']);
        $this->approver = $this->person(['inventory.request.view.all', 'inventory.request.approve']);
        $this->keeper = $this->person(['inventory.request.prepare', 'inventory.request.issue', 'inventory.return.receive']);
        $this->actingAs($this->keeper);
    }

    public function test_the_sentence_names_product_place_and_both_quantities(): void
    {
        $exception = InsufficientStockException::forShortfall(18, 0, 'Kabel Fiber Optic Single', 'm', 'AUDIT POP Bandung Timur');

        $this->assertSame('Stok Kabel Fiber Optic Single di AUDIT POP Bandung Timur tidak cukup: tersedia 0 m, diminta 18 m.', $exception->getMessage());
        $this->assertSame(['Kabel Fiber Optic Single', 'm', 'AUDIT POP Bandung Timur', '18', '0'], [$exception->productName, $exception->unit, $exception->locationName, $exception->requested, $exception->available]);
        $this->assertSame('Stok Router di Gudang tidak cukup: tersedia 1.000,5, diminta 2.000.', InsufficientStockException::forShortfall(2000, 1000.5, 'Router', '', 'Gudang')->getMessage(), 'a product without a unit adds no stray space');
    }

    public function test_approving_more_than_is_free_names_the_product_and_the_warehouse(): void
    {
        $this->stock(quantity: 10, reserved: 7);
        $request = $this->submittedRequest(5);

        try {
            InventoryRequestService::approve($request, $this->approver);
            $this->fail('Five were asked for and three are free.');
        } catch (InsufficientStockException $e) {
            $this->assertSame(self::SENTENCE, $e->getMessage());
            $this->assertStringNotContainsString('KBL-001', $e->getMessage(), 'the SKU is not what people read');
            $this->assertStringNotContainsString('WH-01', $e->getMessage(), 'nor the code of the warehouse');
        }

        $this->assertSame('submitted', $request->fresh()->status);
        $this->assertSame('7.00', Stock::query()->sole()->reserved_quantity, 'nothing was reserved');
    }

    public function test_a_second_line_that_falls_short_leaves_the_first_unreserved(): void
    {
        $other = Product::factory()->create(['company_id' => $this->company->id, 'unit_id' => $this->product->unit_id, 'name' => 'Konektor SC']);
        $this->stock(quantity: 10);
        Stock::query()->create(['company_id' => $this->company->id, 'product_id' => $other->id, 'location_id' => $this->warehouse->id, 'quantity' => 1, 'reserved_quantity' => 0]);
        $request = InventoryRequestService::create($this->technician, [
            'submit' => true,
            'warehouse_location_id' => $this->warehouse->id,
            'reason' => 'Pemasangan',
            'items' => [
                ['product_id' => $this->product->id, 'quantity_requested' => 4],
                ['product_id' => $other->id, 'quantity_requested' => 2],
            ],
        ]);

        try {
            InventoryRequestService::approve($request, $this->approver);
            $this->fail('The second line is short.');
        } catch (InsufficientStockException $e) {
            $this->assertSame('Stok Konektor SC di Gudang Utama tidak cukup: tersedia 1 m, diminta 2 m.', $e->getMessage());
        }

        $this->assertSame(['0.00', '0.00'], Stock::query()->orderBy('id')->pluck('reserved_quantity')->all(), 'the whole approval was rolled back');
    }

    public function test_the_approval_answers_the_dialog_and_the_json_client_with_the_sentence(): void
    {
        $this->stock(quantity: 10, reserved: 7);
        $request = $this->submittedRequest(5);

        $this->actingAs($this->approver)->postJson(route('admin.inventory.requests.approve', $request))
            ->assertStatus(422)
            ->assertJsonPath('message', self::SENTENCE);

        $this->actingAs($this->approver)->from(route('admin.inventory.requests.show', $request))
            ->post(route('admin.inventory.requests.approve', $request))
            ->assertRedirect(route('admin.inventory.requests.show', $request))
            ->assertSessionHasErrors(['quantity' => self::SENTENCE]);
    }

    public function test_return_refusals_name_the_product_not_the_line(): void
    {
        $case = $this->issuedCase(10);
        $line = $case->lines()->sole();

        $mismatch = 'Jumlah tidak cocok untuk Kabel Drop: diserahkan (10) harus sama dengan terpakai (1) + dikembalikan (1) + selisih (0).';
        $this->assertRefusal($mismatch, fn () => MaterialReturnService::declareUsage($case, $this->technician, ['lines' => [
            ['id' => $line->id, 'actual_used_qty' => 1, 'declared_good_return_qty' => 1, 'declared_discrepancy_qty' => 0],
        ]]), $line->public_id);

        $this->assertRefusal('Data deklarasi untuk Kabel Drop tidak ditemukan.', fn () => MaterialReturnService::declareUsage($case, $this->technician, ['lines' => [
            ['id' => $line->id + 1000, 'actual_used_qty' => 10, 'declared_good_return_qty' => 0, 'declared_discrepancy_qty' => 0],
        ]]), $line->public_id);

        MaterialReturnService::declareUsage($case, $this->technician, ['lines' => [
            ['id' => $line->id, 'actual_used_qty' => 6, 'declared_good_return_qty' => 4, 'declared_discrepancy_qty' => 0],
        ]]);

        $this->assertRefusal(
            'Jumlah tidak cocok untuk Kabel Drop: diserahkan (10) harus sama dengan terpakai (6) + diterima (1) + rusak (0) + hilang (0) + lainnya (0).',
            fn () => MaterialReturnService::receiveAccept($case->fresh(), $this->keeper, ['lines' => [
                ['id' => $line->id, 'accepted_good_return_qty' => 1, 'damaged_return_qty' => 0, 'lost_qty' => 0, 'other_discrepancy_qty' => 0],
            ]]),
            $line->public_id,
        );

        $this->assertRefusal('Data verifikasi fisik untuk Kabel Drop tidak ditemukan.', fn () => MaterialReturnService::receiveAccept($case->fresh(), $this->keeper, ['lines' => [
            ['id' => $line->id + 1000, 'accepted_good_return_qty' => 4],
        ]]), $line->public_id);
    }

    private function assertRefusal(string $message, callable $call, string $opaqueId): void
    {
        try {
            $call();
            $this->fail("Expected the refusal: {$message}");
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertSame($message, $e->getMessage());
            $this->assertStringNotContainsString($opaqueId, $e->getMessage());
        }
    }

    /** @param  list<string>  $permissions */
    private function person(array $permissions): User
    {
        $user = User::factory()->create(['company_id' => $this->company->id]);
        $user->givePermissionTo($permissions);

        return $user;
    }

    private function stock(float $quantity, float $reserved = 0): void
    {
        Stock::query()->create(['company_id' => $this->company->id, 'product_id' => $this->product->id, 'location_id' => $this->warehouse->id, 'quantity' => $quantity, 'reserved_quantity' => $reserved]);
    }

    private function submittedRequest(float $quantity): InventoryRequest
    {
        return InventoryRequestService::create($this->technician, [
            'submit' => true,
            'warehouse_location_id' => $this->warehouse->id,
            'reason' => 'Pemasangan pelanggan baru',
            'items' => [['product_id' => $this->product->id, 'quantity_requested' => $quantity]],
        ]);
    }

    /** A request handed over by the warehouse, so its return case waits for the technician's declaration. */
    private function issuedCase(float $quantity): MaterialReturnCase
    {
        $this->stock(quantity: 100);
        $request = $this->submittedRequest($quantity);
        InventoryRequestService::approve($request, $this->approver);
        InventoryRequestService::prepareForPickup($request, $this->keeper);
        InventoryRequestService::issue($request, $this->keeper);

        return MaterialReturnCase::query()->where('inventory_request_id', $request->id)->firstOrFail();
    }
}
