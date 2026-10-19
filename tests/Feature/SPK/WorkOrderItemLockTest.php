<?php

namespace Tests\Feature\SPK;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Inventory\Models\Product;
use Modules\SPK\Database\Factories\WorkOrderFactory;
use Modules\SPK\Models\WorkOrder;
use Modules\SPK\Models\WorkOrderItem;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Tests\Traits\CreatesCompanyUser;
use Tests\Traits\RunsConcurrentWorkers;

/**
 * The item lines of an SPK are the ledger of what its approval takes out of stock. They change only while the SPK can
 * still change (SpkService::ITEM_EDITABLE_STATUSES), and a line is removed under the SPK's lock like every other write.
 */
class WorkOrderItemLockTest extends TestCase
{
    use CreatesCompanyUser;
    use RefreshDatabase;
    use RunsConcurrentWorkers;

    private const LOCKED_MESSAGE = 'Item SPK tidak bisa diubah saat SPK menunggu review, selesai, atau dibatalkan.';

    private User $admin;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->createCompanyUser();
        $this->product = Product::factory()->create(['company_id' => $this->admin->company_id, 'type' => 'consumable']);
    }

    /** @return array<string, array{0: string}> */
    public static function lockedStatuses(): array
    {
        return ['waiting for review' => ['waiting_review'], 'completed' => ['completed'], 'cancelled' => ['cancelled']];
    }

    /** @return array<string, array{0: string}> */
    public static function editableStatuses(): array
    {
        return ['draft' => ['draft'], 'generated' => ['generated'], 'assigned' => ['assigned'], 'in progress' => ['in_progress'], 'rejected' => ['rejected']];
    }

    #[DataProvider('lockedStatuses')]
    public function test_items_cannot_change_while_waiting_for_review_completed_or_cancelled(string $status): void
    {
        $workOrder = $this->workOrder($status);
        $line = $this->line($workOrder, '1');

        $this->actingAs($this->admin)
            ->postJson(route('admin.spk.items.store', $workOrder), ['product_id' => $this->product->public_id, 'quantity_used' => 5])
            ->assertUnprocessable()
            ->assertJsonPath('message', self::LOCKED_MESSAGE);
        $this->deleteJson(route('admin.spk.items.destroy', [$workOrder, $line]))
            ->assertUnprocessable()
            ->assertJsonPath('message', self::LOCKED_MESSAGE);

        $this->assertSame(['1.00'], WorkOrderItem::query()->pluck('quantity_used')->all(), 'the line is still there, as it was');
    }

    #[DataProvider('editableStatuses')]
    public function test_items_change_in_every_status_before_review(string $status): void
    {
        $workOrder = $this->workOrder($status);

        $this->actingAs($this->admin)
            ->post(route('admin.spk.items.store', $workOrder), ['product_id' => $this->product->public_id, 'quantity_used' => 1])
            ->assertRedirect()
            ->assertSessionHas('success', 'Item berhasil ditambahkan.');
        $this->post(route('admin.spk.items.store', $workOrder), ['product_id' => $this->product->public_id, 'quantity_used' => 4])->assertRedirect();

        $line = WorkOrderItem::query()->sole();
        $this->assertSame('4.00', $line->quantity_used, 'the second post edited the one line of the product');

        $this->delete(route('admin.spk.items.destroy', [$workOrder, $line]))
            ->assertRedirect()
            ->assertSessionHas('success', 'Item berhasil dihapus.');
        $this->assertSame(0, WorkOrderItem::query()->count());
        $this->assertDatabaseHas('activity_log', ['log_name' => 'work_order', 'description' => 'item_removed', 'subject_id' => $workOrder->id]);
    }

    public function test_a_line_of_another_spk_is_not_removed_through_this_one(): void
    {
        $workOrder = $this->workOrder('assigned');
        $other = $this->workOrder('assigned');
        $foreign = $this->line($other, '1');

        $this->actingAs($this->admin)
            ->deleteJson(route('admin.spk.items.destroy', [$workOrder, $foreign]))
            ->assertNotFound();

        $this->assertSame(1, WorkOrderItem::query()->count());
    }

    public function test_removing_an_item_waits_for_the_work_order_lock(): void
    {
        $workOrder = $this->workOrder('in_progress');
        $line = $this->line($workOrder, '1');
        $this->actingAs($this->admin);
        $this->commitFixture();

        try {
            $this->withoutExceptionHandling();

            $waited = $this->waitsForRowLock(
                'work_orders',
                $workOrder->id,
                fn () => $this->delete(route('admin.spk.items.destroy', [$workOrder, $line])),
            );

            $this->assertTrue($waited, 'a line must not be deleted while the SPK row is locked by another writer');
        } finally {
            $this->purgeCompany($workOrder->company_id);
        }
    }

    private function workOrder(string $status): WorkOrder
    {
        return WorkOrderFactory::new()->create([
            'company_id' => $this->admin->company_id,
            'type' => 'maintenance',
            'status' => $status,
            'created_by' => $this->admin->id,
        ]);
    }

    private function line(WorkOrder $workOrder, string $used): WorkOrderItem
    {
        return WorkOrderItem::create(['company_id' => $workOrder->company_id, 'work_order_id' => $workOrder->id, 'product_id' => $this->product->id, 'quantity_used' => $used]);
    }
}
