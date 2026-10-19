<?php

namespace Tests\Feature\Inventory;

use App\Models\Core\Location;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\Stock;
use Modules\Inventory\Models\StockAdjustmentRequest;
use Modules\Inventory\Models\StockMovement;
use Modules\Inventory\Services\StockService;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;
use Tests\Traits\CreatesCompanyUser;
use Tests\Traits\RunsConcurrentWorkers;

/**
 * An adjustment request carries the count the stock had when it was asked for. The approval applies the new count only
 * to that stock: when the stock moved in between, it is refused (409) and asked to be counted again, so an approval
 * never writes a count older than the stock it locked.
 */
class AdjustmentApprovalStaleCountTest extends TestCase
{
    use CreatesCompanyUser;
    use RefreshDatabase;
    use RunsConcurrentWorkers;

    private const STALE_MESSAGE = 'Stok berubah sejak dihitung; hitung ulang.';

    private User $approver;

    private User $requester;

    private Product $product;

    private Location $location;

    protected function setUp(): void
    {
        parent::setUp();

        $this->approver = $this->createCompanyUser();
        $this->requester = User::factory()->create(['company_id' => $this->approver->company_id]);
        $this->product = Product::factory()->create(['company_id' => $this->approver->company_id]);
        $this->location = Location::factory()->create(['company_id' => $this->approver->company_id, 'type' => 'site']);
    }

    public function test_approval_refuses_a_count_taken_before_the_stock_moved(): void
    {
        $this->stockOf(100);
        $adjustment = $this->requestCount(95);
        $this->assertSame('100.00', $adjustment->old_quantity);

        $this->actingAs($this->approver);
        StockService::issue($this->product->id, $this->location->id, 10);
        $this->assertSame('90.00', $this->quantity());

        // A script or the mobile app gets the shared 409.
        $this->postJson(route('admin.stocks.adjustments.approve', $adjustment))
            ->assertStatus(409)
            ->assertExactJson(['message' => self::STALE_MESSAGE, 'error' => 'conflict']);

        // A page visit gets the redirect back with the text the toast shows.
        $this->withHeader('X-Inertia', 'true')
            ->post(route('admin.stocks.adjustments.approve', $adjustment))
            ->assertRedirect()
            ->assertSessionHasErrors(['conflict' => self::STALE_MESSAGE])
            ->assertSessionHas('error', self::STALE_MESSAGE);

        $this->assertSame('90.00', $this->quantity(), 'the approval did not write the old count');
        $this->assertSame('pending', $adjustment->fresh()->status, 'the request waits for a new count');
        $this->assertSame(0, StockMovement::query()->where('movement_type', 'adjustment')->count());
    }

    public function test_approval_applies_when_the_stock_did_not_move(): void
    {
        $this->stockOf(100);
        $adjustment = $this->requestCount(95);

        $this->actingAs($this->approver)
            ->post(route('admin.stocks.adjustments.approve', $adjustment))
            ->assertRedirect()
            ->assertSessionHas('success', 'Penyesuaian stok disetujui.');

        $this->assertSame('95.00', $this->quantity());
        $this->assertSame('approved', $adjustment->fresh()->status);
        $this->assertSame('-5.00', StockMovement::query()->where('movement_type', 'adjustment')->sole()->quantity);
    }

    public function test_a_count_taken_where_no_stock_row_existed_still_applies(): void
    {
        $adjustment = $this->requestCount(7);
        $this->assertSame('0.00', $adjustment->old_quantity);

        $this->actingAs($this->approver);
        StockService::approveAdjustment($adjustment, $this->approver->id);

        $this->assertSame('7.00', $this->quantity());
        $this->assertSame('approved', $adjustment->fresh()->status);
    }

    public function test_the_service_refuses_with_a_409_and_the_direct_adjustment_is_not_affected(): void
    {
        $this->stockOf(100);
        $adjustment = $this->requestCount(95);
        $this->actingAs($this->approver);
        StockService::receive($this->product->id, $this->location->id, 3);

        try {
            StockService::approveAdjustment($adjustment, $this->approver->id);
            $this->fail('A count taken at 100 cannot be applied to a stock of 103.');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
            $this->assertSame(self::STALE_MESSAGE, $e->getMessage());
        }

        // The admin's break-glass count has no earlier count to compare with.
        StockService::adjust($this->product->id, $this->location->id, 50, 'Hitung ulang darurat', ['break_glass' => true]);
        $this->assertSame('50.00', $this->quantity());
    }

    public function test_an_issue_racing_the_approval_never_leaves_the_old_count(): void
    {
        $this->stockOf(100);
        $adjustment = $this->requestCount(95);
        $this->commitFixture();

        try {
            // The approver had the request open before the warehouse issued 10: the issue commits first.
            $results = $this->race($this->approvalAndIssue($adjustment, approvalWaits: true), $this->scenario());

            $this->assertTrue($results[1]['ok'], json_encode($results));
            $this->assertFalse($results[0]['ok'], 'the approval of a count taken at 100 is refused: '.json_encode($results));
            $this->assertSame(409, $results[0]['status']);
            $this->assertSame(self::STALE_MESSAGE, $results[0]['message']);
            $this->assertSame('90.00', $this->quantity(), 'never 95, the count the stock had before the issue');
            $this->assertSame('pending', StockAdjustmentRequest::withoutCompany()->findOrFail($adjustment->id)->status);
        } finally {
            $this->purgeCompany($this->approver->company_id);
        }
    }

    public function test_an_approval_that_lands_first_lets_the_issue_run_on_the_new_count(): void
    {
        $this->stockOf(100);
        $adjustment = $this->requestCount(95);
        $this->commitFixture();

        try {
            $results = $this->race($this->approvalAndIssue($adjustment, approvalWaits: false), $this->scenario());

            $this->assertTrue($results[0]['ok'] && $results[1]['ok'], json_encode($results));
            $this->assertSame('85.00', $this->quantity(), '95 after the approval, 10 issued from it');
            $this->assertSame('approved', StockAdjustmentRequest::withoutCompany()->findOrFail($adjustment->id)->status);
        } finally {
            $this->purgeCompany($this->approver->company_id);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function approvalAndIssue(StockAdjustmentRequest $adjustment, bool $approvalWaits): array
    {
        $base = ['company_id' => $this->approver->company_id, 'product_id' => $this->product->id, 'location_id' => $this->location->id];

        return [
            [...$base, 'role' => 'approve', 'user_id' => $this->approver->id, 'adjustment_id' => $adjustment->id, 'pause' => $approvalWaits ? 1500000 : 0],
            [...$base, 'role' => 'issue', 'user_id' => $this->requester->id, 'pause' => $approvalWaits ? 0 : 1500000],
        ];
    }

    private function scenario(): string
    {
        return <<<'PHP'
            config(['logging.default' => 'null']);
            Illuminate\Support\Facades\Auth::setUser(App\Models\User::query()->findOrFail($args['user_id']));

            if ($args['role'] === 'approve') {
                $adjustment = Modules\Inventory\Models\StockAdjustmentRequest::query()->findOrFail($args['adjustment_id']);
                usleep($args['pause']);

                return ['balance' => Modules\Inventory\Services\StockService::approveAdjustment($adjustment, $args['user_id'])->balance_after];
            }

            usleep($args['pause']);
            Modules\Inventory\Services\StockService::issue($args['product_id'], $args['location_id'], 10);

            return ['issued' => 10];
        PHP;
    }

    private function stockOf(float $quantity): Stock
    {
        return Stock::query()->create([
            'company_id' => $this->approver->company_id,
            'product_id' => $this->product->id,
            'location_id' => $this->location->id,
            'quantity' => $quantity,
            'reserved_quantity' => 0,
        ]);
    }

    private function requestCount(float $newQuantity): StockAdjustmentRequest
    {
        $this->actingAs($this->requester);

        return StockService::requestAdjustment($this->product->id, $this->location->id, $newQuantity, 'Hasil hitung ulang gudang');
    }

    private function quantity(): string
    {
        return (string) Stock::withoutCompany()
            ->where('product_id', $this->product->id)
            ->where('location_id', $this->location->id)
            ->value('quantity');
    }
}
