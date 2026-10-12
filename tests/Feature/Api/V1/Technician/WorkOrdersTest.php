<?php

namespace Tests\Feature\Api\V1\Technician;

use App\Models\Core\Customer;
use App\Models\Core\CustomerAddress;
use App\Models\Core\Location;
use App\Models\Core\ServiceSubscription;
use App\Models\User;
use App\Services\Core\CompanyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Inventory\Models\Category;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\Unit;
use Modules\Service\Models\ServicePackage;
use Modules\SPK\Database\Factories\WorkOrderFactory;
use Modules\SPK\Models\WorkOrder;
use Modules\SPK\Models\WorkOrderItem;
use Tests\TestCase;
use Tests\Traits\InteractsWithMobileApi;

class WorkOrdersTest extends TestCase
{
    use InteractsWithMobileApi;
    use RefreshDatabase;

    public function test_a_technician_opens_an_assigned_work_order_detail_shape(): void
    {
        $technician = $this->technicianLogin();
        $token = $this->issueToken($technician, 'technician');
        [$workOrder] = $this->workOrderFor($technician, 'assigned', withLocation: false);

        $this->api('GET', "technician/work-orders/{$workOrder->id}", token: $token)
            ->assertOk()
            ->assertJsonPath('data.id', $workOrder->id)
            ->assertJsonPath('data.customer.name', $workOrder->customer->name)
            ->assertJsonPath('data.subscription.package_name', $workOrder->subscription->servicePackage->name)
            ->assertJsonPath('data.site.label', 'Install')
            ->assertJsonPath('data.items.0.product_name', 'Fiber Cable')
            ->assertJsonPath('data.actions.start', true)
            ->assertJsonMissingPath('data.created_by')
            ->assertJsonMissingPath('data.approved_by')
            ->assertJsonMissingPath('data.network_account');
    }

    public function test_start_accepts_gps_check_in_and_records_distance(): void
    {
        $technician = $this->technicianLogin();
        $token = $this->issueToken($technician, 'technician');
        [$workOrder] = $this->workOrderFor($technician, 'assigned', lat: -6.2000000, lng: 106.8166667);

        $this->api('POST', "technician/work-orders/{$workOrder->id}/start", [
            'check_in' => [
                'lat' => -6.2000000,
                'lng' => 106.8166667,
                'accuracy_m' => 12.4,
                'captured_at' => now()->subMinute()->toIso8601String(),
            ],
        ], $token)->assertOk()
            ->assertJsonPath('data.status', 'in_progress')
            ->assertJsonPath('data.actions.submit', true);

        $properties = DB::table('activity_log')
            ->where('log_name', 'work_order')
            ->where('description', 'check_in')
            ->where('subject_id', $workOrder->id)
            ->value('properties');

        $this->assertIsString($properties);
        $decoded = json_decode($properties, true);
        $this->assertSame('recorded', $decoded['status']);
        $this->assertSame(0, $decoded['distance_m']);
        $this->assertSame(12.4, $decoded['accuracy_m']);
    }

    public function test_start_records_unavailable_check_in_reason(): void
    {
        $technician = $this->technicianLogin();
        $token = $this->issueToken($technician, 'technician');
        [$workOrder] = $this->workOrderFor($technician, 'assigned');

        $this->api('POST', "technician/work-orders/{$workOrder->id}/start", [
            'check_in' => ['unavailable_reason' => 'permission_denied'],
        ], $token)->assertOk()
            ->assertJsonPath('data.check_in.status', 'unavailable')
            ->assertJsonPath('data.check_in.reason', 'permission_denied');

        $properties = json_decode((string) DB::table('activity_log')
            ->where('log_name', 'work_order')
            ->where('description', 'check_in')
            ->where('subject_id', $workOrder->id)
            ->value('properties'), true);

        $this->assertSame('unavailable', $properties['status']);
        $this->assertSame('permission_denied', $properties['reason']);
        $this->assertSame($workOrder->company_id, $properties['company_id']);
    }

    public function test_start_rejects_invalid_check_in_and_reports_none_without_one(): void
    {
        $technician = $this->technicianLogin();
        $token = $this->issueToken($technician, 'technician');
        [$workOrder] = $this->workOrderFor($technician, 'assigned');

        $this->api('POST', "technician/work-orders/{$workOrder->id}/start", [
            'check_in' => ['lat' => 12, 'lng' => 181, 'captured_at' => now()->addHour()->toIso8601String()],
        ], $token)->assertUnprocessable()
            ->assertJsonValidationErrors(['check_in.lng', 'check_in.captured_at']);

        $this->api('GET', "technician/work-orders/{$workOrder->id}", token: $token)
            ->assertJsonPath('data.status', 'assigned')
            ->assertJsonPath('data.check_in.status', 'none');
    }

    public function test_submit_needs_evidence_and_then_moves_to_waiting_review(): void
    {
        $technician = $this->technicianLogin();
        $token = $this->issueToken($technician, 'technician');
        [$workOrder] = $this->workOrderFor($technician, 'in_progress');

        $this->api('POST', "technician/work-orders/{$workOrder->id}/submit", token: $token)
            ->assertStatus(422)
            ->assertJsonPath('code', 'unprocessable');

        $workOrder->addMediaFromString('evidence')
            ->usingFileName('proof.jpg')
            ->withCustomProperties(['company_id' => $workOrder->company_id, 'type' => 'photo', 'uploaded_by' => $technician->id])
            ->toMediaCollection('evidence');

        $this->api('POST', "technician/work-orders/{$workOrder->id}/submit", token: $token)
            ->assertOk()
            ->assertJsonPath('data.status', 'waiting_review');
    }

    public function test_other_technician_cannot_start_and_other_company_is_404(): void
    {
        $technician = $this->technicianLogin();
        $other = $this->technicianLogin($technician->company);
        [$workOrder] = $this->workOrderFor($technician, 'assigned');

        $this->api('POST', "technician/work-orders/{$workOrder->id}/start", token: $this->issueToken($other, 'technician'))
            ->assertForbidden();
        $this->api('GET', "technician/work-orders/{$workOrder->id}", token: $this->issueToken($other, 'technician'))
            ->assertNotFound();

        $stranger = $this->technicianLogin();
        $this->api('GET', "technician/work-orders/{$workOrder->id}", token: $this->issueToken($stranger, 'technician'))
            ->assertNotFound();
    }

    /** @return array{0: WorkOrder, 1: Product} */
    private function workOrderFor(User $technician, string $status, bool $withLocation = true, ?float $lat = null, ?float $lng = null): array
    {
        $company = $technician->company;
        CompanyService::useCompanyForTests((int) $company->id);
        $customer = Customer::factory()->create(['company_id' => $company->id, 'phone' => '08123456789']);
        $address = CustomerAddress::factory()->create([
            'company_id' => $company->id,
            'customer_id' => $customer->id,
            'label' => 'Install',
            'address' => 'Jl. Mawar 1',
            'city' => 'Jakarta',
            'lat' => $lat ?? -6.2000000,
            'lng' => $lng ?? 106.8166667,
            'is_installation_point' => true,
        ]);
        $subscription = ServiceSubscription::factory()->create([
            'customer_id' => $customer->id,
            'service_package_id' => ServicePackage::factory()->create(['company_id' => $company->id])->id,
            'installation_address_id' => $address->id,
        ]);
        $location = $withLocation ? Location::factory()->create(['company_id' => $company->id, 'lat' => $lat, 'lng' => $lng]) : null;
        $workOrder = WorkOrderFactory::new()->create([
            'company_id' => $company->id,
            'status' => $status,
            'assigned_to' => $technician->id,
            'customer_id' => $customer->id,
            'subscription_id' => $subscription->id,
            'location_id' => $location?->id,
            'created_by' => $technician->id,
        ]);
        $unit = Unit::factory()->create(['company_id' => $company->id, 'symbol' => 'm'.Str::random(4)]);
        $product = Product::factory()->create([
            'company_id' => $company->id,
            'category_id' => Category::factory()->create(['company_id' => $company->id, 'unit_id' => $unit->id])->id,
            'unit_id' => $unit->id,
            'name' => 'Fiber Cable',
        ]);
        WorkOrderItem::query()->create([
            'company_id' => $company->id,
            'work_order_id' => $workOrder->id,
            'product_id' => $product->id,
            'quantity_reserved' => 20,
            'quantity_used' => 0,
        ]);

        return [$workOrder->fresh(['customer', 'subscription.servicePackage', 'subscription.installationAddress', 'location']), $product];
    }
}
