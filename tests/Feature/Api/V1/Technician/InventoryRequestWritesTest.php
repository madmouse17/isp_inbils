<?php

namespace Tests\Feature\Api\V1\Technician;

use App\Models\Core\Location;
use App\Models\User;
use App\Services\Core\CompanyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Modules\Inventory\Models\Category;
use Modules\Inventory\Models\InventoryRequest;
use Modules\Inventory\Models\InventoryRequestItem;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\Unit;
use Modules\Inventory\Notifications\InventoryRequestSubmittedNotification;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use Tests\Traits\InteractsWithMobileApi;

/**
 * Asking the warehouse for material from the technician app, and withdrawing the ask: the web's service and
 * rules, with the request queued for the warehouse as soon as it is made.
 */
class InventoryRequestWritesTest extends TestCase
{
    use InteractsWithMobileApi;
    use RefreshDatabase;

    public function test_a_technician_asks_for_material_and_it_is_queued_for_the_warehouse_at_once(): void
    {
        Notification::fake();
        $technician = $this->technicianLogin();
        $approver = User::factory()->create(['company_id' => $technician->company_id]);
        $approver->givePermissionTo('inventory.request.approve');
        $warehouse = $this->location($technician);
        $cable = $this->product($technician, 'Drop Cable', 'mtr');
        $odp = $this->product($technician, 'ODP Box', 'pcs');

        $response = $this->api('POST', 'technician/inventory-requests', [
            'warehouse_location_id' => $warehouse->id,
            'reason' => 'Pasang baru Blok C',
            'items' => [
                ['product_id' => $cable->id, 'quantity_requested' => '150.5', 'note' => 'Drop core'],
                ['product_id' => $odp->id, 'quantity_requested' => 2],
            ],
        ], $this->issueToken($technician, 'technician'))
            ->assertCreated()
            ->assertJsonPath('data.status', 'submitted')
            ->assertJsonPath('data.work_order', null)
            ->assertJsonPath('data.actions.cancel', true)
            ->assertJsonCount(2, 'data.items')
            ->assertJsonPath('data.items.0.product_name', 'Drop Cable')
            ->assertJsonPath('data.items.0.unit', 'mtr')
            ->assertJsonPath('data.items.0.quantity', 150.5)
            ->assertJsonPath('data.items.1.quantity', 2);
        $this->assertSame(['data'], array_keys($response->json()));
        $this->assertSame(['id', 'code', 'status', 'items', 'work_order', 'updated_at', 'actions'], array_keys($response->json('data')));
        $this->assertMatchesRegularExpression('/\S+/', $response->json('data.code'));

        $request = InventoryRequest::withoutCompany()->sole();
        $this->assertSame($request->id, $response->json('data.id'));
        $this->assertSame($technician->id, $request->requested_by);
        $this->assertSame($technician->company_id, $request->company_id);
        $this->assertSame($warehouse->id, $request->warehouse_location_id);
        $this->assertSame('Pasang baru Blok C', $request->reason);
        $this->assertNotNull($request->submitted_at);
        $this->assertSame([$warehouse->id, $warehouse->id], InventoryRequestItem::withoutCompany()->orderBy('id')->pluck('source_location_id')->all());
        $this->assertSame('Drop core', InventoryRequestItem::withoutCompany()->orderBy('id')->first()->note);
        $this->assertTrue(Activity::query()->where('log_name', 'inventory_request')->where('description', 'created_and_submitted')->exists());
        Notification::assertSentTo($approver, InventoryRequestSubmittedNotification::class);

        $this->api('GET', 'technician/inventory-requests', token: $this->issueToken($technician, 'technician'))
            ->assertOk()
            ->assertJsonPath('data.0.id', $request->id)
            ->assertJsonPath('data.0.status', 'submitted')
            ->assertJsonPath('data.0.actions.cancel', true);
    }

    public function test_a_blank_reason_gets_the_services_own_text(): void
    {
        $technician = $this->technicianLogin();
        $cable = $this->product($technician, 'Drop Cable', 'mtr');

        $this->api('POST', 'technician/inventory-requests', [
            'warehouse_location_id' => $this->location($technician)->id,
            'items' => [['product_id' => $cable->id, 'quantity_requested' => 1]],
        ], $this->issueToken($technician, 'technician'))->assertCreated();

        $this->assertSame('Permintaan material inventory', InventoryRequest::withoutCompany()->sole()->reason);
    }

    public function test_the_request_is_validated_and_nothing_is_written_on_a_422(): void
    {
        $technician = $this->technicianLogin();
        $foreigner = $this->technicianLogin();
        $warehouse = $this->location($technician);
        $cable = $this->product($technician, 'Drop Cable', 'mtr');
        $token = $this->issueToken($technician, 'technician');
        $item = ['product_id' => $cable->id, 'quantity_requested' => 1];
        $ok = ['warehouse_location_id' => $warehouse->id, 'items' => [$item]];

        $cases = [
            'warehouse_location_id' => [
                [...$ok, 'warehouse_location_id' => null],
                [...$ok, 'warehouse_location_id' => 9999999],
                [...$ok, 'warehouse_location_id' => $this->location($foreigner)->id],
                [...$ok, 'warehouse_location_id' => $this->location($technician, ['is_active' => false])->id],
                [...$ok, 'warehouse_location_id' => 'x'],
            ],
            'reason' => [[...$ok, 'reason' => str_repeat('a', 2001)]],
            'items' => [
                [...$ok, 'items' => []],
                ['warehouse_location_id' => $warehouse->id],
                [...$ok, 'items' => 'x'],
            ],
            'items.0.product_id' => [
                [...$ok, 'items' => [['quantity_requested' => 1]]],
                [...$ok, 'items' => [['product_id' => 9999999, 'quantity_requested' => 1]]],
                [...$ok, 'items' => [['product_id' => $this->product($foreigner, 'Foreign', 'mtr')->id, 'quantity_requested' => 1]]],
                [...$ok, 'items' => [['product_id' => $this->product($technician, 'Old', 'mtr', ['is_active' => false])->id, 'quantity_requested' => 1]]],
                [...$ok, 'items' => [['product_id' => $this->product($technician, 'ONT', 'pcs', ['type' => 'asset'])->id, 'quantity_requested' => 1]]],
            ],
            'items.0.quantity_requested' => [
                [...$ok, 'items' => [['product_id' => $cable->id]]],
                [...$ok, 'items' => [['product_id' => $cable->id, 'quantity_requested' => 0]]],
                [...$ok, 'items' => [['product_id' => $cable->id, 'quantity_requested' => -2]]],
                [...$ok, 'items' => [['product_id' => $cable->id, 'quantity_requested' => '1.234']]],
                [...$ok, 'items' => [['product_id' => $cable->id, 'quantity_requested' => 'many']]],
            ],
            'items.0.note' => [[...$ok, 'items' => [[...$item, 'note' => str_repeat('n', 256)]]]],
            'items.1.product_id' => [[...$ok, 'items' => [$item, $item]]],
        ];

        foreach ($cases as $field => $bodies) {
            foreach ($bodies as $body) {
                $this->api('POST', 'technician/inventory-requests', $body, $token)
                    ->assertStatus(422)
                    ->assertJsonPath('code', 'validation_failed')
                    ->assertJsonValidationErrors([$field], 'errors');
            }
        }

        $this->assertSame(0, InventoryRequest::withoutCompany()->count());
        $this->assertSame(0, InventoryRequestItem::withoutCompany()->count());
    }

    public function test_a_login_without_the_permission_to_ask_is_refused(): void
    {
        $technician = $this->technicianLogin();
        $cable = $this->product($technician, 'Drop Cable', 'mtr');
        $token = $this->issueToken($technician, 'technician');
        Role::findByName('technician', 'web')->revokePermissionTo('inventory.request.create');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->api('POST', 'technician/inventory-requests', [
            'warehouse_location_id' => $this->location($technician)->id,
            'items' => [['product_id' => $cable->id, 'quantity_requested' => 1]],
        ], $token)->assertForbidden()->assertJsonPath('code', 'forbidden');

        $this->assertSame(0, InventoryRequest::withoutCompany()->count());
    }

    public function test_a_technician_cancels_their_own_waiting_request(): void
    {
        $technician = $this->technicianLogin();
        $token = $this->issueToken($technician, 'technician');

        foreach (['draft', 'submitted'] as $status) {
            $request = $this->request($technician, $status);

            $this->api('POST', "technician/inventory-requests/{$request->id}/cancel", ['cancellation_reason' => 'Sudah ada stok di mobil'], $token)
                ->assertOk()
                ->assertJsonPath('data.id', $request->id)
                ->assertJsonPath('data.status', 'cancelled')
                ->assertJsonPath('data.actions.cancel', false)
                ->assertJsonPath('data.items.0.product_name', 'Drop Cable')
                ->assertJsonPath('data.items.0.unit', 'mtr');

            $fresh = InventoryRequest::withoutCompany()->findOrFail($request->id);
            $this->assertSame('cancelled', $fresh->status);
            $this->assertSame($technician->id, $fresh->cancelled_by);
            $this->assertSame('Sudah ada stok di mobil', $fresh->cancellation_reason);
            $this->assertNotNull($fresh->cancelled_at);
        }

        $this->assertSame(2, Activity::query()->where('log_name', 'inventory_request')->where('description', 'cancelled')->count());
    }

    public function test_the_reason_to_cancel_is_optional_and_bounded(): void
    {
        $technician = $this->technicianLogin();
        $token = $this->issueToken($technician, 'technician');
        $request = $this->request($technician, 'submitted');

        $this->api('POST', "technician/inventory-requests/{$request->id}/cancel", ['cancellation_reason' => str_repeat('a', 1001)], $token)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['cancellation_reason'], 'errors');
        $this->assertSame('submitted', $request->fresh()->status);

        $this->api('POST', "technician/inventory-requests/{$request->id}/cancel", token: $token)->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->assertNull($request->fresh()->cancellation_reason);
    }

    public function test_a_request_the_warehouse_has_taken_up_cannot_be_cancelled_and_the_list_says_so(): void
    {
        $technician = $this->technicianLogin();
        $token = $this->issueToken($technician, 'technician');
        $flags = [];

        foreach (['draft' => true, 'submitted' => true, 'approved_reserved' => false, 'preparing' => false, 'ready_for_pickup' => false, 'issued' => false, 'rejected' => false, 'cancelled' => false] as $status => $cancellable) {
            $request = $this->request($technician, $status);
            $flags[$request->id] = $cancellable;

            if (! $cancellable) {
                $this->api('POST', "technician/inventory-requests/{$request->id}/cancel", token: $token)
                    ->assertForbidden()
                    ->assertJsonPath('code', 'forbidden');
                $this->assertSame($status, $request->fresh()->status);
            }
        }

        $listed = collect($this->api('GET', 'technician/inventory-requests?per_page=50', token: $token)->assertOk()->json('data'))
            ->mapWithKeys(fn (array $row): array => [$row['id'] => $row['actions']['cancel']])->all();
        ksort($listed);
        $this->assertSame($flags, $listed);
    }

    public function test_only_the_requester_may_cancel_and_another_company_is_404(): void
    {
        $technician = $this->technicianLogin();
        $colleague = $this->technicianLogin($technician->company);
        $stranger = $this->technicianLogin();
        $request = $this->request($technician, 'submitted');
        $uri = "technician/inventory-requests/{$request->id}/cancel";

        $this->api('POST', $uri, token: $this->issueToken($colleague, 'technician'))->assertForbidden()->assertJsonPath('code', 'forbidden');
        $this->api('POST', $uri, ['cancellation_reason' => str_repeat('a', 1001)], $this->issueToken($colleague, 'technician'))->assertForbidden();
        $this->api('POST', $uri, token: $this->issueToken($stranger, 'technician'))->assertNotFound();
        $this->api('POST', 'technician/inventory-requests/9999999/cancel', token: $this->issueToken($technician, 'technician'))->assertNotFound();

        $this->assertSame('submitted', $request->fresh()->status);
    }

    public function test_a_retry_with_the_same_key_creates_and_cancels_once(): void
    {
        $technician = $this->technicianLogin();
        $token = $this->issueToken($technician, 'technician');
        $cable = $this->product($technician, 'Drop Cable', 'mtr');
        $body = ['warehouse_location_id' => $this->location($technician)->id, 'items' => [['product_id' => $cable->id, 'quantity_requested' => 3]]];
        $key = (string) Str::uuid();

        $first = $this->api('POST', 'technician/inventory-requests', $body, $token, ['Idempotency-Key' => $key])->assertCreated();
        $replay = $this->api('POST', 'technician/inventory-requests', $body, $token, ['Idempotency-Key' => $key])->assertCreated()->assertHeader('Idempotent-Replayed', 'true');
        $this->assertSame($first->getContent(), $replay->getContent());
        $this->assertSame(1, InventoryRequest::withoutCompany()->count());

        $cancelKey = (string) Str::uuid();
        $uri = 'technician/inventory-requests/'.$first->json('data.id').'/cancel';
        $one = $this->api('POST', $uri, token: $token, headers: ['Idempotency-Key' => $cancelKey])->assertOk();
        $two = $this->api('POST', $uri, token: $token, headers: ['Idempotency-Key' => $cancelKey])->assertOk()->assertHeader('Idempotent-Replayed', 'true');
        $this->assertSame($one->getContent(), $two->getContent());
        // Another key is another action, and the request is no longer waiting.
        $this->api('POST', $uri, token: $token)->assertForbidden();
    }

    /** @param  array<string, mixed>  $overrides */
    private function location(User $owner, array $overrides = []): Location
    {
        return Location::factory()->create(['company_id' => $owner->company_id, 'type' => 'site', ...$overrides]);
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

    private function request(User $technician, string $status): InventoryRequest
    {
        CompanyService::useCompanyForTests((int) $technician->company_id);
        $location = $this->location($technician);
        $request = InventoryRequest::query()->create([
            'company_id' => $technician->company_id,
            'code' => 'REQ-'.Str::upper(Str::random(8)),
            'requested_by' => $technician->id,
            'warehouse_location_id' => $location->id,
            'status' => $status,
            'reason' => 'Need cable',
        ]);
        InventoryRequestItem::query()->create([
            'company_id' => $technician->company_id,
            'inventory_request_id' => $request->id,
            'product_id' => $this->product($technician, 'Drop Cable', 'mtr')->id,
            'source_location_id' => $location->id,
            'quantity_requested' => 12,
        ]);

        return $request;
    }
}
