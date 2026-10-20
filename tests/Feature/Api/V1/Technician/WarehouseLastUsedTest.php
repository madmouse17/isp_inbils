<?php

namespace Tests\Feature\Api\V1\Technician;

use App\Models\Core\Location;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Inventory\Models\InventoryRequest;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\Stock;
use Tests\TestCase;
use Tests\Traits\InteractsWithMobileApi;

/**
 * The warehouse picker of a new request starts on the place the technician asked from last: the warehouses carry
 * `last_used`, true for the warehouse of the caller's latest request and for no other.
 */
class WarehouseLastUsedTest extends TestCase
{
    use InteractsWithMobileApi;
    use RefreshDatabase;

    public function test_nothing_is_last_used_before_the_first_request(): void
    {
        $technician = $this->technicianLogin();
        $this->warehouse($technician, 'Gudang Alpha');
        $this->warehouse($technician, 'Gudang Zulu');

        $this->assertSame([], $this->lastUsed($technician));
        $this->assertSame([false, false], array_column($this->warehouses($technician), 'last_used'));
    }

    public function test_the_warehouse_of_the_latest_request_is_the_last_used(): void
    {
        $technician = $this->technicianLogin();
        $alpha = $this->warehouse($technician, 'Gudang Alpha');
        $zulu = $this->warehouse($technician, 'Gudang Zulu');

        $this->request($technician, $alpha, 'ready_for_pickup');
        $this->assertSame(['Gudang Alpha'], $this->lastUsed($technician));

        // Whatever became of the request: a cancelled one is still where the technician went last.
        $this->request($technician, $zulu, 'cancelled');
        $this->assertSame(['Gudang Zulu'], $this->lastUsed($technician));
    }

    public function test_a_colleagues_requests_do_not_count(): void
    {
        $technician = $this->technicianLogin();
        $colleague = User::factory()->create(['company_id' => $technician->company_id]);
        $alpha = $this->warehouse($technician, 'Gudang Alpha');
        $zulu = $this->warehouse($technician, 'Gudang Zulu');

        $this->request($technician, $alpha, 'submitted');
        $this->request($colleague, $zulu, 'submitted');

        $this->assertSame(['Gudang Alpha'], $this->lastUsed($technician));
    }

    public function test_a_request_without_a_warehouse_is_skipped(): void
    {
        $technician = $this->technicianLogin();
        $alpha = $this->warehouse($technician, 'Gudang Alpha');
        $this->warehouse($technician, 'Gudang Zulu');

        $this->request($technician, $alpha, 'submitted');
        $this->request($technician, null, 'draft');

        $this->assertSame(['Gudang Alpha'], $this->lastUsed($technician));
    }

    public function test_a_warehouse_that_is_no_longer_offered_marks_nothing(): void
    {
        $technician = $this->technicianLogin();
        $alpha = $this->warehouse($technician, 'Gudang Alpha');
        $closed = $this->warehouse($technician, 'Closed Depot');

        $this->request($technician, $alpha, 'submitted');
        $this->request($technician, $closed, 'submitted');
        $closed->update(['is_active' => false]);

        $this->assertSame([], $this->lastUsed($technician));
        $this->assertSame(['Gudang Alpha'], array_column($this->warehouses($technician), 'name'));
    }

    /** A place that holds stock, which is what the endpoint offers. */
    private function warehouse(User $owner, string $name): Location
    {
        $location = Location::factory()->create(['company_id' => $owner->company_id, 'type' => 'site', 'name' => $name]);
        Stock::query()->create([
            'company_id' => $owner->company_id,
            'product_id' => Product::factory()->create(['company_id' => $owner->company_id])->id,
            'location_id' => $location->id,
            'quantity' => 10,
        ]);

        return $location;
    }

    private function request(User $requester, ?Location $warehouse, string $status): InventoryRequest
    {
        return InventoryRequest::query()->create([
            'company_id' => $requester->company_id,
            'code' => 'REQ-'.Str::upper(Str::random(6)),
            'requested_by' => $requester->id,
            'warehouse_location_id' => $warehouse?->id,
            'status' => $status,
        ]);
    }

    /** @return list<array<string, mixed>> */
    private function warehouses(User $technician): array
    {
        return $this->api('GET', 'technician/warehouses', token: $this->issueToken($technician, 'technician'))->assertOk()->json('data');
    }

    /** @return list<string> */
    private function lastUsed(User $technician): array
    {
        return array_values(array_column(array_filter($this->warehouses($technician), fn (array $warehouse): bool => $warehouse['last_used'] === true), 'name'));
    }
}
