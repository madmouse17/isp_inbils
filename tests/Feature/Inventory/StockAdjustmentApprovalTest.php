<?php

namespace Tests\Feature\Inventory;

use App\Models\Core\Company;
use App\Models\Core\Location;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Inventory\Models\Category;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\Stock;
use Modules\Inventory\Models\StockAdjustmentRequest;
use Modules\Inventory\Models\StockMovement;
use Modules\Inventory\Models\Unit;
use Modules\Inventory\Services\StockService;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;
use Tests\Traits\WithoutAppOnlyRedirect;

/**
 * Two-step stock adjustment (role-persona-sod D2=A): operator requests,
 * a different controller approves or rejects; admin keeps a flagged break-glass path.
 */
class StockAdjustmentApprovalTest extends TestCase
{
    use RefreshDatabase;
    use WithoutAppOnlyRedirect;

    private Company $company;

    private Product $product;

    private Location $location;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        app()['cache']->forget('spatie.permission.cache');

        $this->company = Company::factory()->create();
        // BelongsToCompany scopes writes from Auth::user()->company_id
        $this->actingAs(User::factory()->create(['company_id' => $this->company->id]));

        $unit = Unit::query()->create(['name' => 'Piece', 'symbol' => 'PCS']);
        $category = Category::query()->create(['name' => 'CPE', 'code' => 'CPE', 'unit_id' => $unit->id]);
        $this->product = Product::query()->create([
            'category_id' => $category->id,
            'unit_id' => $unit->id,
            'sku' => 'CPE-001',
            'name' => 'ONT',
            'type' => 'consumable',
            'track_stock' => true,
            'min_stock' => 0,
            'is_active' => true,
        ]);
        $this->location = Location::query()->create([
            'name' => 'WH-A',
            'code' => 'WH-A',
            'type' => 'site',
            'is_active' => true,
        ]);

        StockService::receive($this->product->id, $this->location->id, 10);
    }

    public function test_staff_request_is_stored_pending_and_does_not_move_stock(): void
    {
        $staff = $this->userWithRole('staff');

        $this->actingAs($staff)
            ->post(route('admin.stocks.adjust'), $this->payload(4, 'cycle count short'))
            ->assertRedirect();

        $this->assertDatabaseHas('stock_adjustment_requests', [
            'company_id' => $this->company->id,
            'product_id' => $this->product->id,
            'location_id' => $this->location->id,
            'status' => 'pending',
            'requested_by' => $staff->id,
            'reason' => 'cycle count short',
            'approved_by' => null,
        ]);
        $request = StockAdjustmentRequest::query()->firstOrFail();
        $this->assertEquals(10, $request->old_quantity);
        $this->assertEquals(4, $request->new_quantity);
        $this->assertEquals(-6, $request->delta);
        $this->assertSame(10.0, $this->quantity());
        $this->assertSame(0, StockMovement::query()->where('movement_type', 'adjustment')->count());

        $audit = Activity::query()->where('description', 'stock_adjustment_requested')->firstOrFail();
        $this->assertSame($staff->id, $audit->causer_id);
        $this->assertSame($request->id, $audit->subject_id);
    }

    public function test_request_does_not_create_a_stock_row_for_an_untracked_location(): void
    {
        $empty = Location::query()->create(['name' => 'WH-B', 'code' => 'WH-B', 'type' => 'site', 'is_active' => true]);
        $staff = $this->userWithRole('staff');

        $this->actingAs($staff)
            ->post(route('admin.stocks.adjust'), $this->payload(3, 'found in bin', $empty->public_id))
            ->assertRedirect();

        $this->assertSame(0, Stock::query()->where('location_id', $empty->id)->count());
        $this->assertEquals(0, StockAdjustmentRequest::query()->firstOrFail()->old_quantity);
    }

    public function test_request_validation_requires_reason_and_company_scoped_ids(): void
    {
        $staff = $this->userWithRole('staff');
        $otherCompany = Company::factory()->create();
        $foreignLocation = Location::factory()->create([
            'company_id' => $otherCompany->id,
            'name' => 'Foreign',
            'type' => 'site',
            'is_active' => true,
        ]);

        $this->actingAs($staff)
            ->post(route('admin.stocks.adjust'), $this->payload(4, ''))
            ->assertSessionHasErrors('note');
        $this->actingAs($staff)
            ->post(route('admin.stocks.adjust'), $this->payload(4, 'ok', $foreignLocation->public_id))
            ->assertSessionHasErrors('location_id');

        $this->assertSame(0, StockAdjustmentRequest::query()->count());
    }

    public function test_manager_approval_applies_adjustment_and_records_actor_evidence(): void
    {
        $staff = $this->userWithRole('staff');
        $manager = $this->userWithRole('manager');
        $adjustment = $this->requestAs($staff, 4, 'cycle count short');

        $this->actingAs($manager)
            ->post(route('admin.stocks.adjustments.approve', $adjustment))
            ->assertRedirect();

        $adjustment->refresh();
        $this->assertSame('approved', $adjustment->status);
        $this->assertSame($manager->id, $adjustment->approved_by);
        $this->assertNotNull($adjustment->approved_at);
        $this->assertSame(4.0, $this->quantity());

        $movement = StockMovement::query()->where('movement_type', 'adjustment')->firstOrFail();
        $this->assertEquals(-6, $movement->quantity);
        $this->assertSame($manager->id, $movement->created_by);
        $this->assertSame('cycle count short', $movement->note);

        $audit = Activity::query()->where('description', 'stock_adjustment')->latest('id')->firstOrFail();
        $this->assertSame($manager->id, $audit->causer_id);
        $props = $audit->properties->toArray();
        $this->assertSame($adjustment->id, $props['adjustment_request_id']);
        $this->assertSame($staff->id, $props['requested_by']);
        $this->assertSame($manager->id, $props['approved_by']);
        $this->assertEquals(10, $props['old_quantity']);
        $this->assertEquals(4, $props['new_quantity']);
        $this->assertArrayNotHasKey('break_glass', $props);
    }

    public function test_requester_cannot_decide_own_request_even_with_both_permissions(): void
    {
        $both = User::factory()->create(['company_id' => $this->company->id]);
        $both->givePermissionTo(['inventory.view', 'inventory.stock.adjust.request', 'inventory.stock.adjust.approve']);
        $adjustment = $this->requestAs($both, 4, 'self serve');

        $this->actingAs($both)
            ->post(route('admin.stocks.adjustments.approve', $adjustment))
            ->assertForbidden();
        $this->actingAs($both)
            ->post(route('admin.stocks.adjustments.reject', $adjustment), ['rejection_reason' => 'changed my mind'])
            ->assertForbidden();

        $this->assertSame('pending', $adjustment->fresh()->status);
        $this->assertSame(10.0, $this->quantity());
    }

    public function test_staff_and_technician_cannot_approve_or_reject(): void
    {
        $staff = $this->userWithRole('staff');
        $other = $this->userWithRole('staff');
        $technician = $this->userWithRole('technician');
        $adjustment = $this->requestAs($staff, 4, 'cycle count short');

        foreach ([$other, $technician] as $actor) {
            $this->actingAs($actor)->post(route('admin.stocks.adjustments.approve', $adjustment))->assertForbidden();
            $this->actingAs($actor)
                ->post(route('admin.stocks.adjustments.reject', $adjustment), ['rejection_reason' => 'nope nope'])
                ->assertForbidden();
        }

        $this->assertSame('pending', $adjustment->fresh()->status);
        $this->assertSame(10.0, $this->quantity());
    }

    public function test_rejection_requires_reason_and_leaves_stock_untouched(): void
    {
        $staff = $this->userWithRole('staff');
        $manager = $this->userWithRole('manager');
        $adjustment = $this->requestAs($staff, 4, 'cycle count short');

        $this->actingAs($manager)
            ->post(route('admin.stocks.adjustments.reject', $adjustment), ['rejection_reason' => ''])
            ->assertSessionHasErrors('rejection_reason');
        $this->assertSame('pending', $adjustment->fresh()->status);

        $this->actingAs($manager)
            ->post(route('admin.stocks.adjustments.reject', $adjustment), ['rejection_reason' => 'recount first'])
            ->assertRedirect();

        $adjustment->refresh();
        $this->assertSame('rejected', $adjustment->status);
        $this->assertSame($manager->id, $adjustment->approved_by);
        $this->assertSame('recount first', $adjustment->rejection_reason);
        $this->assertSame(10.0, $this->quantity());
        $this->assertSame(0, StockMovement::query()->where('movement_type', 'adjustment')->count());

        $audit = Activity::query()->where('description', 'stock_adjustment_rejected')->firstOrFail();
        $this->assertSame($manager->id, $audit->causer_id);
        $this->assertSame($staff->id, $audit->properties['requested_by']);
    }

    public function test_decided_request_cannot_be_decided_again(): void
    {
        $staff = $this->userWithRole('staff');
        $manager = $this->userWithRole('manager');
        $adjustment = $this->requestAs($staff, 4, 'cycle count short');
        $stale = StockAdjustmentRequest::query()->findOrFail($adjustment->id);

        $this->actingAs($manager)->post(route('admin.stocks.adjustments.approve', $adjustment))->assertRedirect();
        $this->actingAs($manager)->post(route('admin.stocks.adjustments.approve', $adjustment))->assertForbidden();
        $this->actingAs($manager)
            ->post(route('admin.stocks.adjustments.reject', $adjustment), ['rejection_reason' => 'too late'])
            ->assertForbidden();

        // A stale in-memory copy (double click, queue replay) must hit the locked re-read, not apply twice.
        try {
            StockService::approveAdjustment($stale, $manager->id);
            $this->fail('Second approval must abort.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }

        $this->assertSame(1, StockMovement::query()->where('movement_type', 'adjustment')->count());
        $this->assertSame(4.0, $this->quantity());
    }

    public function test_manager_of_another_company_cannot_see_or_decide_the_request(): void
    {
        $staff = $this->userWithRole('staff');
        $adjustment = $this->requestAs($staff, 4, 'cycle count short');

        $otherCompany = Company::factory()->create();
        $foreignManager = User::factory()->create(['company_id' => $otherCompany->id]);
        $this->grantRole($foreignManager, 'manager');

        $this->actingAs($foreignManager)->post(route('admin.stocks.adjustments.approve', $adjustment))->assertNotFound();
        $this->actingAs($foreignManager)
            ->post(route('admin.stocks.adjustments.reject', $adjustment), ['rejection_reason' => 'not yours'])
            ->assertNotFound();

        $this->actingAs($foreignManager)
            ->get(route('admin.stocks.index'))
            ->assertInertia(fn (Assert $page) => $page->has('adjustmentRequests.data', 0));
        $this->assertSame('pending', StockAdjustmentRequest::withoutCompany()->findOrFail($adjustment->id)->status);
    }

    public function test_break_glass_direct_adjust_is_admin_only_and_flagged_in_audit(): void
    {
        $manager = $this->userWithRole('manager');
        $staff = $this->userWithRole('staff');
        $admin = $this->userWithRole('admin');
        $payload = $this->payload(7, 'sole admin recount');

        $this->actingAs($manager)->post(route('admin.stocks.adjust.direct'), $payload)->assertForbidden();
        $this->actingAs($staff)->post(route('admin.stocks.adjust.direct'), $payload)->assertForbidden();
        $this->assertSame(10.0, $this->quantity());

        $this->actingAs($admin)->post(route('admin.stocks.adjust.direct'), $payload)->assertRedirect();

        $this->assertSame(7.0, $this->quantity());
        $movement = StockMovement::query()->where('movement_type', 'adjustment')->firstOrFail();
        $this->assertSame($admin->id, $movement->created_by);
        $audit = Activity::query()->where('description', 'stock_adjustment')->latest('id')->firstOrFail();
        $this->assertSame($admin->id, $audit->causer_id);
        $this->assertTrue($audit->properties['break_glass']);
        $this->assertSame(0, StockAdjustmentRequest::query()->count());
    }

    public function test_admin_cannot_approve_own_request_either(): void
    {
        $admin = $this->userWithRole('admin');
        $adjustment = $this->requestAs($admin, 4, 'admin recount');

        $this->actingAs($admin)->post(route('admin.stocks.adjustments.approve', $adjustment))->assertForbidden();

        $this->assertSame('pending', $adjustment->fresh()->status);
    }

    public function test_approval_below_reserved_quantity_is_rejected_atomically(): void
    {
        $staff = $this->userWithRole('staff');
        $manager = $this->userWithRole('manager');
        $adjustment = $this->requestAs($staff, 2, 'cycle count short');
        Stock::query()->where('product_id', $this->product->id)->update(['reserved_quantity' => 5]);

        $this->actingAs($manager)
            ->postJson(route('admin.stocks.adjustments.approve', $adjustment))
            ->assertStatus(422);

        $this->assertSame('pending', $adjustment->fresh()->status);
        $this->assertSame(10.0, $this->quantity());
    }

    public function test_stocks_index_exposes_pending_requests_by_persona(): void
    {
        $staff = $this->userWithRole('staff');
        $otherStaff = $this->userWithRole('staff');
        $manager = $this->userWithRole('manager');
        $technician = $this->userWithRole('technician');
        $mine = $this->requestAs($staff, 4, 'mine');
        $theirs = $this->requestAs($otherStaff, 5, 'theirs');

        $this->actingAs($manager)->get(route('admin.stocks.index'))->assertInertia(
            fn (Assert $page) => $page
                ->component('Admin/Inventory/Stocks/Index')
                ->where('can.adjust_approve', true)
                ->where('can.adjust_request', false)
                ->has('adjustmentRequests.data', 2)
                ->where('adjustmentRequests.data.0.id', $mine->public_id)
                ->where('adjustmentRequests.data.0.can.approve', true)
                ->where('adjustmentRequests.data.0.can.reject', true)
                ->where('adjustmentRequests.data.0.requester.name', $staff->name)
        );

        $this->actingAs($staff)->get(route('admin.stocks.index'))->assertInertia(
            fn (Assert $page) => $page
                ->where('can.adjust_request', true)
                ->where('can.adjust_approve', false)
                ->has('adjustmentRequests.data', 1)
                ->where('adjustmentRequests.data.0.id', $mine->public_id)
                ->where('adjustmentRequests.data.0.can.approve', false)
        );

        $this->actingAs($technician)->get(route('admin.stocks.index'))->assertInertia(
            fn (Assert $page) => $page
                ->where('can.adjust_request', false)
                ->where('can.adjust_approve', false)
                ->has('adjustmentRequests.data', 0)
        );

        $this->assertNotSame($mine->id, $theirs->id);
    }

    private function quantity(): float
    {
        return (float) Stock::query()
            ->where('product_id', $this->product->id)
            ->where('location_id', $this->location->id)
            ->value('quantity');
    }

    private function requestAs(User $user, float $newQuantity, string $reason): StockAdjustmentRequest
    {
        $this->actingAs($user);

        return StockService::requestAdjustment($this->product->id, $this->location->id, $newQuantity, $reason);
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create(['company_id' => $this->company->id]);
        $this->grantRole($user, $role);

        return $user;
    }

    /** @return array<string, mixed> */
    private function payload(float $newQuantity, string $note, ?string $locationId = null): array
    {
        return [
            'product_id' => $this->product->public_id,
            'location_id' => $locationId ?? $this->location->public_id,
            'new_quantity' => $newQuantity,
            'note' => $note,
        ];
    }
}
