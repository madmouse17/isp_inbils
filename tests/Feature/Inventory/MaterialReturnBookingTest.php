<?php

namespace Tests\Feature\Inventory;

use App\Models\Core\Company;
use App\Models\Core\Location;
use App\Models\User;
use App\Services\Core\CompanyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Modules\Inventory\Models\MaterialReturnCase;
use Modules\Inventory\Models\MaterialReturnLine;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\Stock;
use Modules\Inventory\Models\StockMovement;
use Modules\Inventory\Services\InventoryRequestService;
use Modules\Inventory\Services\MaterialReturnService;
use Tests\TestCase;
use Tests\Traits\WithoutAppOnlyRedirect;

/**
 * F8-D10: the good quantity a warehouse takes back is booked as a return, not as a purchase receipt. The movement list
 * (and the stock card) then show "Pengembalian" instead of a second "Masuk" that was never bought.
 */
class MaterialReturnBookingTest extends TestCase
{
    use RefreshDatabase;
    use WithoutAppOnlyRedirect;

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
        $this->warehouse = Location::factory()->create(['company_id' => $this->company->id, 'type' => 'site']);
        $this->product = Product::factory()->create(['company_id' => $this->company->id, 'name' => 'Kabel Drop']);
        Stock::query()->create(['company_id' => $this->company->id, 'product_id' => $this->product->id, 'location_id' => $this->warehouse->id, 'quantity' => 100, 'reserved_quantity' => 0]);

        $this->technician = $this->person(['inventory.request.create', 'inventory.return.declare']);
        $this->approver = $this->person(['inventory.request.view.all', 'inventory.request.approve']);
        $this->keeper = $this->person(['inventory.request.prepare', 'inventory.request.issue', 'inventory.return.receive']);
        $this->actingAs($this->keeper);
    }

    public function test_the_accepted_good_quantity_is_booked_as_one_return_movement(): void
    {
        [$case, $line] = $this->declaredCase(used: 6, good: 4);

        MaterialReturnService::receiveAccept($case, $this->keeper, ['lines' => [$this->accepted($line, good: 4)]]);

        $this->assertSame('94.00', $this->stock()->quantity, '90 left after the hand-over, 4 came back');
        $movement = StockMovement::query()->where('movement_type', 'return')->sole();
        $this->assertSame(
            [$this->product->id, $this->warehouse->id, '4.00', '94.00', MaterialReturnLine::class, $line->id, $this->keeper->id],
            [$movement->product_id, $movement->to_location_id, $movement->quantity, $movement->balance_after, $movement->reference_type, $movement->reference_id, $movement->created_by],
        );
        $this->assertSame('Penerimaan Fisik Pengembalian Material: '.$case->inventoryRequest->code, $movement->note);
        $this->assertSame(0, StockMovement::query()->where('movement_type', 'receive')->count(), 'no purchase receipt was invented');
    }

    public function test_only_the_good_quantity_is_booked_when_the_rest_is_damaged_or_lost(): void
    {
        [$case, $line] = $this->declaredCase(used: 6, good: 1, discrepancy: 3, code: 'damaged');

        MaterialReturnService::receiveAccept($case, $this->keeper, ['lines' => [$this->accepted($line, good: 2, damaged: 1, lost: 1)]]);

        $this->assertSame('92.00', $this->stock()->quantity);
        $this->assertSame(['2.00'], StockMovement::query()->where('movement_type', 'return')->pluck('quantity')->all());
        $this->assertSame('exception_open', $case->fresh()->status);
    }

    public function test_a_return_with_nothing_good_books_no_movement(): void
    {
        [$case, $line] = $this->declaredCase(used: 10, good: 0);

        MaterialReturnService::receiveAccept($case, $this->keeper, ['lines' => [$this->accepted($line, good: 0)]]);

        $this->assertSame(0, StockMovement::query()->where('movement_type', 'return')->count());
        $this->assertSame('90.00', $this->stock()->quantity);
        $this->assertSame('reconciled', $case->fresh()->status);
    }

    public function test_the_return_shows_up_in_the_movement_list_as_a_return(): void
    {
        [$case, $line] = $this->declaredCase(used: 6, good: 4);
        MaterialReturnService::receiveAccept($case, $this->keeper, ['lines' => [$this->accepted($line, good: 4)]]);
        $admin = $this->person(['inventory.view']);

        $this->actingAs($admin)->get(route('admin.stock-movements.index', ['movement_type' => 'return']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('movements.data', 1)->where('movements.data.0.movement_type', 'return'));
    }

    private function stock(): Stock
    {
        return Stock::query()->where('product_id', $this->product->id)->where('location_id', $this->warehouse->id)->sole();
    }

    /**
     * @return array<string, mixed>
     */
    private function accepted(MaterialReturnLine $line, float $good, float $damaged = 0, float $lost = 0): array
    {
        return [
            'id' => $line->id,
            'accepted_good_return_qty' => $good,
            'damaged_return_qty' => $damaged,
            'lost_qty' => $lost,
            'other_discrepancy_qty' => 0,
        ];
    }

    /**
     * Ten handed over, the technician has declared: the case waits for the warehouse to count what came back.
     *
     * @return array{0: MaterialReturnCase, 1: MaterialReturnLine}
     */
    private function declaredCase(float $used, float $good, float $discrepancy = 0, ?string $code = null): array
    {
        $request = InventoryRequestService::create($this->technician, [
            'submit' => true,
            'warehouse_location_id' => $this->warehouse->id,
            'reason' => 'Pemasangan pelanggan baru',
            'items' => [['product_id' => $this->product->id, 'quantity_requested' => 10]],
        ]);
        InventoryRequestService::approve($request, $this->approver);
        InventoryRequestService::prepareForPickup($request, $this->keeper);
        InventoryRequestService::issue($request, $this->keeper);

        $case = MaterialReturnCase::query()->where('inventory_request_id', $request->id)->firstOrFail();
        $line = $case->lines()->sole();
        MaterialReturnService::declareUsage($case, $this->technician, ['lines' => [[
            'id' => $line->id,
            'actual_used_qty' => $used,
            'declared_good_return_qty' => $good,
            'declared_discrepancy_qty' => $discrepancy,
            'discrepancy_code' => $code,
            'discrepancy_reason' => $code === null ? null : 'Tergores saat ditarik',
        ]]]);

        return [$case->fresh(['inventoryRequest']), $line->fresh()];
    }

    /** @param  list<string>  $permissions */
    private function person(array $permissions): User
    {
        $user = User::factory()->create(['company_id' => $this->company->id]);
        $user->givePermissionTo($permissions);

        return $user;
    }
}
