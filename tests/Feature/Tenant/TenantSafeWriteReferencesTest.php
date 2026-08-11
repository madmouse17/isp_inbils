<?php

namespace Tests\Feature\Tenant;

use App\Http\Requests\Admin\StoreEmployeeRequest;
use App\Http\Requests\Admin\UpdateEmployeeRequest;
use App\Models\Core\Company;
use App\Models\Core\Customer;
use App\Models\Core\CustomerAddress;
use App\Models\Core\Location;
use App\Models\User;
use App\Rules\BelongsToCompany;
use App\Rules\TenantOwnedMorph;
use App\Rules\TenantOwnedParentChild;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Validator;
use Modules\Inventory\Http\Requests\StockIssueRequest;
use Modules\Inventory\Http\Requests\StockReceiveRequest;
use Modules\Inventory\Models\Category;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\Unit;
use Modules\Inventory\Services\StockService;
use Modules\SPK\Models\WorkOrder;
use Modules\SPK\Models\WorkOrderItem;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class TenantSafeWriteReferencesTest extends TestCase
{
    use DatabaseTransactions;

    private Company $companyA;

    private Company $companyB;

    private User $userA;

    protected function setUp(): void
    {
        parent::setUp();

        $this->companyA = Company::factory()->create();
        $this->companyB = Company::factory()->create();
        $this->userA = User::factory()->create(['company_id' => $this->companyA->id]);
        $this->actingAs($this->userA);
    }

    public function test_belongs_to_company_rejects_cross_tenant_id(): void
    {
        $local = Customer::factory()->create(['company_id' => $this->companyA->id]);
        $foreign = Customer::factory()->create(['company_id' => $this->companyB->id]);

        $this->assertTrue($this->passes(
            ['value' => $local->id],
            ['value' => [new BelongsToCompany('customers')]]
        ));
        $this->assertFalse($this->passes(
            ['value' => $foreign->id],
            ['value' => [new BelongsToCompany('customers')]]
        ));
    }

    public function test_parent_child_rejects_address_from_other_customer(): void
    {
        $customerA = Customer::factory()->create(['company_id' => $this->companyA->id]);
        $customerB = Customer::factory()->create(['company_id' => $this->companyA->id]);
        $addressB = CustomerAddress::query()->create([
            'customer_id' => $customerB->id,
            'label' => 'Home',
            'address' => 'Jl. B',
            'is_primary' => true,
        ]);

        $this->assertFalse($this->passes(
            [
                'customer_id' => $customerA->id,
                'installation_address_id' => $addressB->id,
            ],
            [
                'installation_address_id' => [
                    new TenantOwnedParentChild('customer_addresses', 'customer_id', 'customer_id'),
                ],
            ]
        ));
    }

    public function test_morph_rejects_unknown_type_and_cross_tenant_id(): void
    {
        $foreign = Customer::factory()->create(['company_id' => $this->companyB->id]);
        $local = Customer::factory()->create(['company_id' => $this->companyA->id]);

        $rule = new TenantOwnedMorph([
            'customer' => Customer::class,
        ], 'subject_type');

        $this->assertFalse($this->passes(
            ['subject_type' => 'customer', 'subject_id' => $foreign->id],
            ['subject_id' => [$rule]]
        ));

        $this->assertTrue($this->passes(
            ['subject_type' => 'customer', 'subject_id' => $local->id],
            ['subject_id' => [new TenantOwnedMorph(['customer' => Customer::class], 'subject_type')]]
        ));

        $this->assertFalse($this->passes(
            ['subject_type' => 'not_allowlisted', 'subject_id' => $local->id],
            ['subject_id' => [new TenantOwnedMorph(['customer' => Customer::class], 'subject_type')]]
        ));
    }

    public function test_employee_requests_reject_foreign_user_id(): void
    {
        $foreignUser = User::factory()->create(['company_id' => $this->companyB->id]);

        $payload = [
            'user_id' => $foreignUser->id,
            'employee_number' => 'EMP-FOREIGN',
        ];

        $this->assertFalse($this->passes($payload, (new StoreEmployeeRequest())->rules()));
        $this->assertFalse($this->passes($payload, (new UpdateEmployeeRequest())->rules()));
    }

    public function test_stock_receive_and_issue_requests_reject_free_text_references(): void
    {
        $unit = Unit::query()->create(['name' => 'Piece', 'symbol' => 'PCS']);
        $category = Category::query()->create(['name' => 'Routers', 'code' => 'RTR', 'unit_id' => $unit->id]);
        $product = Product::query()->create([
            'category_id' => $category->id,
            'unit_id' => $unit->id,
            'sku' => 'RTR-REQ',
            'name' => 'Router Request',
            'type' => 'consumable',
            'track_stock' => true,
            'min_stock' => 0,
            'is_active' => true,
        ]);
        $location = Location::query()->create([
            'code' => 'REQ-WH',
            'name' => 'Request WH',
            'type' => 'site',
            'path' => 'REQ-WH',
            'is_active' => true,
        ]);

        $payload = [
            'product_id' => $product->id,
            'location_id' => $location->id,
            'quantity' => 1,
            'reference_type' => 'purchase_order',
            'reference_id' => 99,
        ];

        $this->assertFalse($this->passes($payload, (new StockReceiveRequest())->rules()));
        $this->assertFalse($this->passes($payload, (new StockIssueRequest())->rules()));
    }

    public function test_stock_receive_request_accepts_same_company_work_order_item_reference(): void
    {
        $unit = Unit::query()->create(['name' => 'Piece', 'symbol' => 'PCS-REQ2']);
        $category = Category::query()->create(['name' => 'Routers 2', 'code' => 'RTR2', 'unit_id' => $unit->id]);
        $product = Product::query()->create([
            'category_id' => $category->id,
            'unit_id' => $unit->id,
            'sku' => 'RTR-REQ-2',
            'name' => 'Router Request 2',
            'type' => 'consumable',
            'track_stock' => true,
            'min_stock' => 0,
            'is_active' => true,
        ]);
        $location = Location::query()->create([
            'code' => 'REQ-WH-2',
            'name' => 'Request WH 2',
            'type' => 'site',
            'path' => 'REQ-WH-2',
            'is_active' => true,
        ]);
        $workOrder = WorkOrder::forceCreate([
            'company_id' => $this->companyA->id,
            'code' => 'REQ-WO',
            'type' => 'maintenance',
            'title' => 'Request WO',
            'status' => 'draft',
            'source' => 'manual',
            'priority' => 'medium',
            'created_by' => $this->userA->id,
        ]);
        $item = WorkOrderItem::forceCreate([
            'company_id' => $this->companyA->id,
            'work_order_id' => $workOrder->id,
            'product_id' => $product->id,
            'quantity_reserved' => 0,
            'quantity_used' => 0,
        ]);

        $this->assertTrue($this->passes([
            'product_id' => $product->id,
            'location_id' => $location->id,
            'quantity' => 1,
            'reference_type' => WorkOrderItem::class,
            'reference_id' => $item->id,
        ], (new StockReceiveRequest())->rules()));

        $this->assertTrue($this->passes([
            'product_id' => $product->id,
            'location_id' => $location->id,
            'quantity' => 1,
            'reference_type' => WorkOrderItem::class,
            'reference_id' => $item->id,
        ], (new StockIssueRequest())->rules()));
    }

    public function test_stock_service_rejects_foreign_location_on_receive(): void
    {
        $unit = Unit::query()->create(['name' => 'Piece', 'symbol' => 'PCS']);
        $category = Category::query()->create(['name' => 'Routers', 'code' => 'RTR', 'unit_id' => $unit->id]);
        $product = Product::query()->create([
            'category_id' => $category->id,
            'unit_id' => $unit->id,
            'sku' => 'RTR-P0A',
            'name' => 'Router P0A',
            'type' => 'consumable',
            'track_stock' => true,
            'min_stock' => 0,
            'is_active' => true,
        ]);
        $foreignLocation = Location::withoutCompany()->forceCreate([
            'company_id' => $this->companyB->id,
            'code' => 'WH-B',
            'name' => 'Foreign WH',
            'type' => 'site',
            'path' => 'WH-B',
            'is_active' => true,
        ]);

        try {
            StockService::receive($product->id, $foreignLocation->id, 1);
            $this->fail('Expected HttpException for foreign location.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $rules
     */
    private function passes(array $data, array $rules): bool
    {
        return ! Validator::make($data, $rules)->fails();
    }
}
