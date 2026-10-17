<?php

namespace Tests\Feature\Api\V1\Technician;

use App\Http\Middleware\Api\EnforceIdempotency;
use App\Models\Core\Location;
use App\Models\User;
use App\Services\Core\CompanyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Modules\Inventory\Models\Category;
use Modules\Inventory\Models\InventoryRequest;
use Modules\Inventory\Models\InventoryRequestItem;
use Modules\Inventory\Models\MaterialReturnCase;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\Unit;
use Modules\Inventory\Services\MaterialReturnService;
use Modules\SPK\Database\Factories\WorkOrderFactory;
use Modules\SPK\Models\WorkOrder;
use Tests\TestCase;
use Tests\Traits\InteractsWithMobileApi;

class MaterialReturnsTest extends TestCase
{
    use InteractsWithMobileApi;
    use RefreshDatabase;

    public function test_a_technician_lists_and_opens_only_own_cases_with_work_order_filter(): void
    {
        $technician = $this->technicianLogin();
        $other = $this->technicianLogin($technician->company);
        [$myCase, $workOrder] = $this->caseFor($technician);
        [$otherCase] = $this->caseFor($other);
        $technician->givePermissionTo('inventory.return.view.all');

        $token = $this->issueToken($technician, 'technician');

        $this->api('GET', 'technician/material-returns?work_order_id='.$workOrder->public_id, token: $token)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $myCase->public_id)
            ->assertJsonPath('data.0.lines.0.product.name', 'Fiber Cable');

        $this->api('GET', "technician/material-returns/{$myCase->public_id}", token: $token)->assertOk();
        $this->api('GET', "technician/material-returns/{$otherCase->public_id}", token: $token)->assertNotFound();
    }

    public function test_declare_moves_case_and_replays_by_service_key_after_cache_flush(): void
    {
        $technician = $this->technicianLogin();
        [$case] = $this->caseFor($technician);
        $line = $case->lines()->firstOrFail();
        $key = (string) Str::uuid();
        $payload = ['lines' => [[
            'id' => $line->public_id,
            'actual_used_qty' => 4,
            'declared_good_return_qty' => 5,
            'declared_discrepancy_qty' => 1,
            'discrepancy_code' => 'damaged',
            'discrepancy_reason' => 'cracked',
        ]]];
        $token = $this->issueToken($technician, 'technician');

        $this->api('POST', "technician/material-returns/{$case->public_id}/declare", $payload, $token, ['Idempotency-Key' => $key])
            ->assertOk()
            ->assertJsonPath('data.status', 'awaiting_receipt')
            ->assertJsonPath('data.lines.0.actual_used_qty', 4);

        Cache::forget(EnforceIdempotency::cacheKey($technician->id, $key));

        $this->api('POST', "technician/material-returns/{$case->public_id}/declare", $payload, $token, ['Idempotency-Key' => $key])
            ->assertOk()
            ->assertJsonPath('data.status', 'awaiting_receipt');

        $this->assertSame(1, MaterialReturnCase::query()->where('id', $case->id)->where('idempotency_key', $key)->count());
    }

    public function test_declare_same_key_with_different_payload_is_conflict_after_cache_flush(): void
    {
        $technician = $this->technicianLogin();
        [$case] = $this->caseFor($technician);
        $line = $case->lines()->firstOrFail();
        $key = (string) Str::uuid();
        $token = $this->issueToken($technician, 'technician');

        $this->api('POST', "technician/material-returns/{$case->public_id}/declare", ['lines' => [[
            'id' => $line->public_id,
            'actual_used_qty' => 10,
            'declared_good_return_qty' => 0,
            'declared_discrepancy_qty' => 0,
        ]]], $token, ['Idempotency-Key' => $key])->assertOk();

        Cache::forget(EnforceIdempotency::cacheKey($technician->id, $key));

        $this->api('POST', "technician/material-returns/{$case->public_id}/declare", ['lines' => [[
            'id' => $line->public_id,
            'actual_used_qty' => 9,
            'declared_good_return_qty' => 1,
            'declared_discrepancy_qty' => 0,
        ]]], $token, ['Idempotency-Key' => $key])->assertStatus(409)->assertJsonPath('code', 'conflict');
    }

    public function test_spk_submit_succeeds_only_after_all_return_cases_are_declared(): void
    {
        $technician = $this->technicianLogin();
        [$case, $workOrder] = $this->caseFor($technician, spkStatus: 'in_progress');
        $workOrder->addMediaFromString('proof')->usingFileName('proof.jpg')->withCustomProperties(['company_id' => $workOrder->company_id, 'type' => 'photo', 'uploaded_by' => $technician->id])->toMediaCollection('evidence');
        $token = $this->issueToken($technician, 'technician');

        $this->api('POST', "technician/work-orders/{$workOrder->public_id}/submit", token: $token)
            ->assertStatus(422)
            ->assertJsonPath('code', 'unprocessable');

        $line = $case->lines()->firstOrFail();
        MaterialReturnService::declareUsage($case, $technician, ['lines' => [[
            'id' => $line->id,
            'actual_used_qty' => 10,
            'declared_good_return_qty' => 0,
            'declared_discrepancy_qty' => 0,
        ]], 'idempotency_key' => (string) Str::uuid()]);

        $this->api('POST', "technician/work-orders/{$workOrder->public_id}/submit", token: $token)
            ->assertOk()
            ->assertJsonPath('data.status', 'waiting_review');
    }

    /** @return array{0: MaterialReturnCase, 1: WorkOrder} */
    private function caseFor(User $technician, string $spkStatus = 'assigned'): array
    {
        $company = $technician->company;
        CompanyService::useCompanyForTests((int) $company->id);
        $workOrder = WorkOrderFactory::new()->create(['company_id' => $company->id, 'assigned_to' => $technician->id, 'status' => $spkStatus]);
        $location = Location::factory()->create(['company_id' => $company->id]);
        $unit = Unit::factory()->create(['company_id' => $company->id, 'symbol' => 'm'.Str::random(4)]);
        $product = Product::factory()->create([
            'company_id' => $company->id,
            'category_id' => Category::factory()->create(['company_id' => $company->id, 'unit_id' => $unit->id])->id,
            'unit_id' => $unit->id,
            'name' => 'Fiber Cable',
        ]);
        $request = InventoryRequest::query()->create([
            'company_id' => $company->id,
            'code' => 'REQ-'.Str::upper(Str::random(6)),
            'requested_by' => $technician->id,
            'warehouse_location_id' => $location->id,
            'source_type' => 'work_order',
            'source_id' => $workOrder->id,
            'status' => 'issued',
        ]);
        InventoryRequestItem::query()->create([
            'company_id' => $company->id,
            'inventory_request_id' => $request->id,
            'product_id' => $product->id,
            'source_location_id' => $location->id,
            'quantity_requested' => 10,
            'quantity_approved' => 10,
            'quantity_issued' => 10,
        ]);

        return [MaterialReturnService::createForIssuedRequest($request)->fresh(['lines.product.unit']), $workOrder->fresh()];
    }
}
