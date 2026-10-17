<?php

namespace Tests\Feature\Api\V1\Technician;

use App\Models\User;
use App\Services\Core\CompanyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Inventory\Models\Category;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\Unit;
use Modules\NetworkAsset\Database\Factories\NetworkAssetFactory;
use Modules\SPK\Database\Factories\WorkOrderFactory;
use Modules\SPK\Models\WorkOrder;
use Modules\SPK\Models\WorkOrderItem;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;
use Tests\Traits\InteractsWithMobileApi;

/**
 * The material lines of an SPK from the technician app: the assigned technician records what was used while the
 * SPK is in progress, one line per product, and takes it back again. Taking it back never destroys the manager's plan:
 * a planned line stays with nothing used, and a serialized asset line is not touched. Nobody else, at no other time.
 */
class WorkOrderItemsTest extends TestCase
{
    use InteractsWithMobileApi;
    use RefreshDatabase;

    public function test_the_assigned_technician_records_material_used_on_an_spk_in_progress(): void
    {
        $technician = $this->technicianLogin();
        $workOrder = $this->workOrder($technician, 'in_progress');
        $cable = $this->product($technician, 'Drop Cable', 'mtr');
        $token = $this->issueToken($technician, 'technician');

        $response = $this->api('POST', "technician/work-orders/{$workOrder->public_id}/items", ['product_id' => $cable->public_id, 'quantity' => '12.5'], $token)
            ->assertCreated()
            ->assertJsonPath('data.product_name', 'Drop Cable')
            ->assertJsonPath('data.unit', 'mtr')
            ->assertJsonPath('data.quantity_used', 12.5)
            ->assertJsonPath('data.quantity_reserved', 0)
            ->assertJsonPath('data.note', null);
        $this->assertSame(['data'], array_keys($response->json()));
        $this->assertSame(['id', 'product_name', 'unit', 'quantity_reserved', 'quantity_used', 'note'], array_keys($response->json('data')));

        $item = WorkOrderItem::withoutCompany()->sole();
        $this->assertSame($item->public_id, $response->json('data.id'));
        $this->assertSame($workOrder->id, $item->work_order_id);
        $this->assertSame($technician->company_id, $item->company_id);
        $this->assertSame('12.50', $item->quantity_used);

        $this->api('GET', "technician/work-orders/{$workOrder->public_id}", token: $token)
            ->assertOk()
            ->assertJsonPath('data.actions.edit_items', true)
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.id', $item->public_id)
            ->assertJsonPath('data.items.0.quantity_used', 12.5);

        $log = Activity::query()->where('log_name', 'work_order')->where('description', 'item_recorded')->sole();
        $this->assertSame($technician->id, $log->getExtraProperty('recorded_by'));
        $this->assertSame($cable->id, $log->getExtraProperty('product_id'));
    }

    public function test_the_same_product_again_replaces_the_used_quantity_and_keeps_a_planned_reservation(): void
    {
        $technician = $this->technicianLogin();
        $workOrder = $this->workOrder($technician, 'in_progress');
        $cable = $this->product($technician, 'Drop Cable', 'mtr');
        $planned = WorkOrderItem::query()->create([
            'company_id' => $technician->company_id, 'work_order_id' => $workOrder->id, 'product_id' => $cable->id,
            'quantity_reserved' => 20, 'quantity_used' => 0, 'note' => 'Planned by the manager',
        ]);
        $token = $this->issueToken($technician, 'technician');

        $this->api('POST', "technician/work-orders/{$workOrder->public_id}/items", ['product_id' => $cable->public_id, 'quantity' => 8], $token)
            ->assertCreated()
            ->assertJsonPath('data.id', $planned->public_id)
            ->assertJsonPath('data.quantity_reserved', 20)
            ->assertJsonPath('data.quantity_used', 8)
            ->assertJsonPath('data.note', 'Planned by the manager');
        $this->api('POST', "technician/work-orders/{$workOrder->public_id}/items", ['product_id' => $cable->public_id, 'quantity' => 3], $token)
            ->assertCreated()
            ->assertJsonPath('data.quantity_used', 3);

        $this->assertSame(1, WorkOrderItem::withoutCompany()->count());
        $this->assertSame('3.00', $planned->fresh()->quantity_used);
        $this->assertSame('20.00', $planned->fresh()->quantity_reserved);
    }

    public function test_quantity_and_product_are_validated_and_nothing_is_written_on_a_422(): void
    {
        $technician = $this->technicianLogin();
        $workOrder = $this->workOrder($technician, 'in_progress');
        $good = $this->product($technician, 'Drop Cable', 'mtr');
        $foreign = $this->product($this->technicianLogin(), 'Foreign Cable', 'mtr');
        $inactive = $this->product($technician, 'Old Cable', 'mtr', ['is_active' => false]);
        $asset = $this->product($technician, 'ONT', 'pcs', ['type' => 'asset']);
        $trashed = $this->product($technician, 'Gone Cable', 'mtr');
        $trashed->delete();
        $token = $this->issueToken($technician, 'technician');
        $uri = "technician/work-orders/{$workOrder->public_id}/items";

        foreach ([null, 0, '0', -1, 'abc', '1.234', 10000000000000.0, [3]] as $quantity) {
            $this->api('POST', $uri, ['product_id' => $good->public_id, 'quantity' => $quantity], $token)
                ->assertStatus(422)
                ->assertJsonPath('code', 'validation_failed')
                ->assertJsonValidationErrors(['quantity'], 'errors');
        }

        foreach ([null, 'x', 9999999, $foreign->public_id, $inactive->public_id, $asset->public_id, $trashed->public_id] as $productId) {
            $this->api('POST', $uri, ['product_id' => $productId, 'quantity' => 1], $token)
                ->assertStatus(422)
                ->assertJsonValidationErrors(['product_id'], 'errors');
        }

        $this->assertSame(0, WorkOrderItem::withoutCompany()->count());
    }

    public function test_only_the_assigned_technician_of_an_spk_in_progress_may_add_or_remove_a_line(): void
    {
        $technician = $this->technicianLogin();
        $colleague = $this->technicianLogin($technician->company);
        $stranger = $this->technicianLogin();
        $workOrder = $this->workOrder($technician, 'in_progress');
        $cable = $this->product($technician, 'Drop Cable', 'mtr');
        $line = $this->line($workOrder, $cable);
        $body = ['product_id' => $cable->public_id, 'quantity' => 5];

        $this->api('POST', "technician/work-orders/{$workOrder->public_id}/items", $body, $this->issueToken($colleague, 'technician'))
            ->assertNotFound()
            ->assertJsonPath('code', 'not_found');
        // The fence comes before the body: a colleague never learns what is wrong with the product.
        $this->api('POST', "technician/work-orders/{$workOrder->public_id}/items", [], $this->issueToken($colleague, 'technician'))->assertNotFound();
        $this->api('DELETE', "technician/work-orders/{$workOrder->public_id}/items/{$line->public_id}", token: $this->issueToken($colleague, 'technician'))->assertNotFound();
        $this->api('POST', "technician/work-orders/{$workOrder->public_id}/items", $body, $this->issueToken($stranger, 'technician'))->assertNotFound();
        $this->api('DELETE', "technician/work-orders/{$workOrder->public_id}/items/{$line->public_id}", token: $this->issueToken($stranger, 'technician'))->assertNotFound();

        $this->assertSame('0.00', $line->fresh()->quantity_used);
        $this->assertSame(1, WorkOrderItem::withoutCompany()->count());

        foreach (['assigned', 'waiting_review', 'completed', 'cancelled'] as $status) {
            $closed = $this->workOrder($technician, $status);
            $closedLine = $this->line($closed, $cable);
            $token = $this->issueToken($technician, 'technician');

            $this->api('POST', "technician/work-orders/{$closed->public_id}/items", $body, $token)->assertForbidden();
            $this->api('DELETE', "technician/work-orders/{$closed->public_id}/items/{$closedLine->public_id}", token: $token)->assertForbidden();
            $this->assertNotNull($closedLine->fresh(), $status);
            $this->assertSame('0.00', $closedLine->fresh()->quantity_used, $status);
        }
    }

    public function test_the_spk_detail_tells_the_app_when_the_lines_are_closed(): void
    {
        $technician = $this->technicianLogin();
        $token = $this->issueToken($technician, 'technician');

        foreach (['assigned' => false, 'in_progress' => true, 'waiting_review' => false] as $status => $editable) {
            $this->api('GET', 'technician/work-orders/'.$this->workOrder($technician, $status)->public_id, token: $token)
                ->assertOk()
                ->assertJsonPath('data.actions.edit_items', $editable);
        }
    }

    public function test_a_technician_removes_a_line_and_a_line_of_another_spk_is_a_404(): void
    {
        $technician = $this->technicianLogin();
        $workOrder = $this->workOrder($technician, 'in_progress');
        $other = $this->workOrder($technician, 'in_progress');
        $cable = $this->product($technician, 'Drop Cable', 'mtr');
        $line = $this->line($workOrder, $cable, ['quantity_used' => 4]);
        $otherLine = $this->line($other, $cable);
        $token = $this->issueToken($technician, 'technician');

        $this->api('DELETE', "technician/work-orders/{$other->public_id}/items/{$line->public_id}", token: $token)->assertNotFound();
        $this->api('DELETE', "technician/work-orders/{$workOrder->public_id}/items/9999999", token: $token)->assertNotFound();
        $this->assertNotNull($line->fresh());

        $this->api('DELETE', "technician/work-orders/{$workOrder->public_id}/items/{$line->public_id}", token: $token)->assertNoContent();

        $this->assertNull(WorkOrderItem::withoutCompany()->find($line->id));
        $this->assertNotNull($otherLine->fresh());
        $log = Activity::query()->where('log_name', 'work_order')->where('description', 'item_removed')->sole();
        $this->assertSame($technician->id, $log->getExtraProperty('removed_by'));
    }

    public function test_withdrawing_a_line_the_manager_planned_clears_the_use_and_keeps_the_plan(): void
    {
        $technician = $this->technicianLogin();
        $workOrder = $this->workOrder($technician, 'in_progress');
        $cable = $this->product($technician, 'Drop Cable', 'mtr');
        $planned = $this->line($workOrder, $cable, ['quantity_reserved' => 20, 'quantity_used' => 8]);
        $token = $this->issueToken($technician, 'technician');
        $uri = "technician/work-orders/{$workOrder->public_id}/items/{$planned->public_id}";

        $this->api('DELETE', $uri, token: $token)->assertNoContent();

        $this->assertSame('0.00', $planned->fresh()->quantity_used);
        $this->assertSame('20.00', $planned->fresh()->quantity_reserved);
        $this->api('GET', "technician/work-orders/{$workOrder->public_id}", token: $token)
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.id', $planned->public_id)
            ->assertJsonPath('data.items.0.quantity_used', 0)
            ->assertJsonPath('data.items.0.quantity_reserved', 20);
        $log = Activity::query()->where('log_name', 'work_order')->where('description', 'item_usage_cleared')->sole();
        $this->assertSame($technician->id, $log->getExtraProperty('cleared_by'));
        $this->assertEquals(8, $log->getExtraProperty('previous_quantity_used'));
        $this->assertSame(0, Activity::query()->where('description', 'item_removed')->count());

        // Withdrawing again changes nothing and leaves no second audit entry.
        $this->api('DELETE', $uri, token: $token)->assertNoContent();
        $this->assertSame(1, Activity::query()->where('description', 'item_usage_cleared')->count());

        // The line is still there to record the use again.
        $this->api('POST', "technician/work-orders/{$workOrder->public_id}/items", ['product_id' => $cable->public_id, 'quantity' => 5], $token)
            ->assertCreated()
            ->assertJsonPath('data.id', $planned->public_id)
            ->assertJsonPath('data.quantity_used', 5)
            ->assertJsonPath('data.quantity_reserved', 20);
    }

    public function test_withdrawing_a_line_with_the_managers_note_keeps_the_note_and_clears_the_use(): void
    {
        $technician = $this->technicianLogin();
        $workOrder = $this->workOrder($technician, 'in_progress');
        $cable = $this->product($technician, 'Drop Cable', 'mtr');
        $noted = $this->line($workOrder, $cable, ['quantity_used' => 3, 'note' => 'Splice at ODP-12']);

        $this->api('DELETE', "technician/work-orders/{$workOrder->public_id}/items/{$noted->public_id}", token: $this->issueToken($technician, 'technician'))
            ->assertNoContent();

        $this->assertSame('0.00', $noted->fresh()->quantity_used);
        $this->assertSame('Splice at ODP-12', $noted->fresh()->note);
    }

    public function test_a_serialized_asset_line_is_not_changed_from_the_app(): void
    {
        $technician = $this->technicianLogin();
        $workOrder = $this->workOrder($technician, 'in_progress');
        $ont = $this->product($technician, 'ONT', 'pcs', ['type' => 'asset']);
        $asset = NetworkAssetFactory::new()->create(['company_id' => $technician->company_id, 'product_id' => $ont->id]);
        $line = $this->line($workOrder, $ont, ['network_asset_id' => $asset->id, 'quantity_used' => 1]);

        $this->api('DELETE', "technician/work-orders/{$workOrder->public_id}/items/{$line->public_id}", token: $this->issueToken($technician, 'technician'))
            ->assertStatus(422)
            ->assertJsonPath('code', 'unprocessable');

        $this->assertSame($asset->id, $line->fresh()->network_asset_id);
        $this->assertSame('1.00', $line->fresh()->quantity_used);
        $this->assertSame(0, Activity::query()->whereIn('description', ['item_removed', 'item_usage_cleared'])->count());
    }

    public function test_a_retry_with_the_same_key_adds_one_line_and_a_retried_delete_still_answers_204(): void
    {
        $technician = $this->technicianLogin();
        $workOrder = $this->workOrder($technician, 'in_progress');
        $cable = $this->product($technician, 'Drop Cable', 'mtr');
        $token = $this->issueToken($technician, 'technician');
        $key = (string) Str::uuid();
        $uri = "technician/work-orders/{$workOrder->public_id}/items";

        $first = $this->api('POST', $uri, ['product_id' => $cable->public_id, 'quantity' => 4], $token, ['Idempotency-Key' => $key])->assertCreated();
        $replay = $this->api('POST', $uri, ['product_id' => $cable->public_id, 'quantity' => 4], $token, ['Idempotency-Key' => $key])->assertCreated()->assertHeader('Idempotent-Replayed', 'true');
        $this->assertSame($first->getContent(), $replay->getContent());
        $this->api('POST', $uri, ['product_id' => $cable->public_id, 'quantity' => 9], $token, ['Idempotency-Key' => $key])
            ->assertStatus(422)
            ->assertJsonPath('code', 'idempotency_conflict');
        $this->assertSame(1, WorkOrderItem::withoutCompany()->count());
        $this->assertSame('4.00', WorkOrderItem::withoutCompany()->sole()->quantity_used);

        $deleteKey = (string) Str::uuid();
        $url = $uri.'/'.$first->json('data.id');
        $this->api('DELETE', $url, token: $token, headers: ['Idempotency-Key' => $deleteKey])->assertNoContent();
        $this->api('DELETE', $url, token: $token, headers: ['Idempotency-Key' => $deleteKey])->assertNoContent()->assertHeader('Idempotent-Replayed', 'true');
        $this->api('DELETE', $url, token: $token)->assertNotFound();
    }

    private function workOrder(User $technician, string $status): WorkOrder
    {
        CompanyService::useCompanyForTests((int) $technician->company_id);

        return WorkOrderFactory::new()->create([
            'company_id' => $technician->company_id,
            'status' => $status,
            'assigned_to' => $technician->id,
            'created_by' => $technician->id,
        ]);
    }

    /** @param  array<string, mixed>  $overrides */
    private function product(User $owner, string $name, string $unit, array $overrides = []): Product
    {
        $unitRow = Unit::withoutCompany()->where('company_id', $owner->company_id)->where('symbol', $unit)->first()
            ?? Unit::factory()->create(['company_id' => $owner->company_id, 'symbol' => $unit, 'name' => $unit.' '.Str::random(4)]);

        return Product::factory()->create([
            'company_id' => $owner->company_id,
            'category_id' => Category::factory()->create(['company_id' => $owner->company_id, 'unit_id' => $unitRow->id])->id,
            'unit_id' => $unitRow->id,
            'name' => $name,
            ...$overrides,
        ]);
    }

    /** @param  array<string, mixed>  $attributes */
    private function line(WorkOrder $workOrder, Product $product, array $attributes = []): WorkOrderItem
    {
        return WorkOrderItem::query()->create([
            'company_id' => $workOrder->company_id, 'work_order_id' => $workOrder->id, 'product_id' => $product->id,
            ...$attributes,
        ]);
    }
}
