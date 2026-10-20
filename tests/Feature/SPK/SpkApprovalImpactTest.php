<?php

namespace Tests\Feature\SPK;

use App\Models\Core\Company;
use App\Models\Core\Customer;
use App\Models\Core\Location;
use App\Models\Core\ServiceSubscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Billing\Models\Invoice;
use Modules\Inventory\Models\InventoryRequest;
use Modules\Inventory\Models\InventoryRequestItem;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\Stock;
use Modules\NetworkAsset\Models\NetworkAccount;
use Modules\SPK\Database\Factories\WorkOrderFactory;
use Modules\SPK\Models\WorkOrder;
use Modules\SPK\Models\WorkOrderItem;
use Modules\SPK\Services\SpkService;
use Modules\SPK\Support\SpkApprovalImpact;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;
use Tests\Traits\BuildsNetworkFixtures;
use Tests\Traits\BuildsSubscriptionBilling;
use Tests\Traits\InteractsWithRadius;
use Tests\Traits\WithoutAppOnlyRedirect;

/**
 * F4-U11: the confirmation of an approval names what the approval does. Every line restates a rule of
 * SpkService::approve, so each test also lets the approval run and reads the result back: a line the approval would
 * not honour is a lie in a dialog that asks for a permanent decision.
 */
class SpkApprovalImpactTest extends TestCase
{
    use BuildsNetworkFixtures;
    use BuildsSubscriptionBilling;
    use InteractsWithRadius;
    use RefreshDatabase;
    use WithoutAppOnlyRedirect;

    private Company $company;

    private User $approver;

    private User $creator;

    private Location $pop;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        Queue::fake();

        foreach (['spk.view', 'spk.approve'] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
    }

    /**
     * An SPK in review, with evidence. The subscription is pending with an installation fee of Rp 250.000 and the
     * company charges 11% tax, so the invoice of a plain installation is Rp 277.500.
     *
     * @param  array<string, mixed>  $settings  company settings
     * @param  array<string, mixed>  $subscription  subscription columns
     */
    private function submitted(string $type = 'installation', array $settings = [], array $subscription = [], string $subscriptionStatus = 'pending', bool $radius = false, ?string $radiusProfile = 'home-20m'): WorkOrder
    {
        $this->company = Company::factory()->create(['settings' => ['tax_ppn_rate' => 11, ...$settings]]);
        $this->creator = User::factory()->create(['company_id' => $this->company->id]);
        $this->approver = User::factory()->create(['company_id' => $this->company->id, 'email_verified_at' => now(), 'is_active' => true]);
        $this->approver->givePermissionTo(['spk.view', 'spk.approve']);
        $this->actingAs($this->creator);
        $this->pop = Location::factory()->create(['company_id' => $this->company->id]);

        if ($radius) {
            $this->fakeRadius();
        }

        $planned = $this->subscriptionIn($this->company, $subscriptionStatus, null, $this->packageWithRadiusProfile($this->company, $radiusProfile));
        $planned->forceFill(['otc_installation_fee' => 250000, ...$subscription])->save();

        $workOrder = WorkOrderFactory::new()->create([
            'company_id' => $this->company->id,
            'type' => $type,
            'status' => 'waiting_review',
            'customer_id' => $planned->customer_id,
            'subscription_id' => $planned->id,
            'location_id' => $this->pop->id,
            'assigned_to' => null,
            'created_by' => $this->creator->id,
        ]);
        $workOrder->addMediaFromString('evidence')
            ->usingFileName('proof.jpg')
            ->withCustomProperties(['company_id' => $this->company->id, 'type' => 'photo', 'uploaded_by' => $this->creator->id])
            ->toMediaCollection('evidence', 'public');

        return $workOrder;
    }

    /** A product the company stocks at the SPK's location (10 on hand) and the SPK used `$used` of. */
    private function used(WorkOrder $workOrder, string $name, string $used, int $price = 25000): Product
    {
        $product = Product::factory()->create(['company_id' => $this->company->id, 'type' => 'consumable', 'name' => $name, 'sell_price' => $price]);
        Stock::create(['company_id' => $this->company->id, 'product_id' => $product->id, 'location_id' => $this->pop->id, 'quantity' => 10, 'reserved_quantity' => 0]);
        WorkOrderItem::create(['company_id' => $this->company->id, 'work_order_id' => $workOrder->id, 'product_id' => $product->id, 'quantity_used' => $used]);

        return $product;
    }

    private function approve(WorkOrder $workOrder): ?Invoice
    {
        $this->actingAs($this->approver);
        SpkService::approve($workOrder);

        return Invoice::query()->where('work_order_id', $workOrder->id)->first();
    }

    private function stockOf(Product $product): float
    {
        return (float) Stock::query()->where('product_id', $product->id)->where('location_id', $this->pop->id)->value('quantity');
    }

    public function test_installation_impact_names_invoice_total_and_send_mode(): void
    {
        $workOrder = $this->submitted();
        $customer = Customer::query()->findOrFail($workOrder->customer_id);
        $subscription = ServiceSubscription::query()->findOrFail($workOrder->subscription_id);

        $this->assertSame([
            "Pelanggan {$customer->code} dan langganan {$subscription->code} menjadi aktif.",
            'Tagihan pemasangan Rp 277.500 dibuat sebagai draf. Kirim dari halaman tagihan.',
        ], SpkApprovalImpact::for($workOrder));

        $this->company->forceFill(['settings' => ['tax_ppn_rate' => 11, 'auto_send_installation_invoice' => true]])->save();

        $this->assertSame(
            'Tagihan pemasangan Rp 277.500 dibuat dan langsung dikirim ke pelanggan.',
            SpkApprovalImpact::for($workOrder->fresh())[1],
        );

        $invoice = $this->approve($workOrder);
        $this->assertSame(['277500.00', 'sent'], [$invoice->total, $invoice->status], 'the line said what the approval did');
    }

    public function test_the_billed_total_follows_the_materials_the_company_bills(): void
    {
        $workOrder = $this->submitted(settings: ['bill_installation_materials' => true]);
        $this->used($workOrder, 'Kabel Drop', '2');

        $this->assertContains(
            'Tagihan pemasangan Rp 333.000 dibuat sebagai draf. Kirim dari halaman tagihan.',
            SpkApprovalImpact::for($workOrder),
            'the fee 250.000 and two metres at 25.000, with 11% tax',
        );
        $this->assertSame('333000.00', $this->approve($workOrder)->total);
    }

    public function test_nothing_billable_says_no_invoice(): void
    {
        $workOrder = $this->submitted(subscription: ['otc_installation_fee' => 0]);

        $this->assertContains('Tidak ada tagihan pemasangan (tidak ada biaya yang ditagihkan).', SpkApprovalImpact::for($workOrder));
        $this->assertNull($this->approve($workOrder), 'and the approval made none');
    }

    public function test_other_types_list_materials_only(): void
    {
        $workOrder = $this->submitted(type: 'maintenance', subscriptionStatus: 'active', radius: true);
        $cable = $this->used($workOrder, 'Kabel Drop', '2');
        $this->used($workOrder, 'Konektor', '0');

        $this->assertSame(
            ['Material terpakai dikurangi dari stok: 1 barang.'],
            SpkApprovalImpact::for($workOrder),
            'no activation (already active), no invoice and no account for a visit that is not an installation; an item nobody used is no material',
        );

        $this->assertNull($this->approve($workOrder), 'a maintenance visit is never billed by its approval');
        $this->assertSame(8.0, $this->stockOf($cable));
        $this->assertNull(NetworkAccount::query()->where('subscription_id', $workOrder->subscription_id)->first());
    }

    public function test_material_a_handover_already_took_out_of_the_warehouse_is_not_counted_twice(): void
    {
        $workOrder = $this->submitted(type: 'maintenance', subscriptionStatus: 'active');
        $fromWarehouse = $this->used($workOrder, 'Konektor', '1');
        $fromSite = $this->used($workOrder, 'Kabel Drop', '2');
        $handover = InventoryRequest::forceCreate([
            'company_id' => $this->company->id,
            'code' => 'REQ-IMPACT-0001',
            'requested_by' => $this->creator->id,
            'warehouse_location_id' => $this->pop->id,
            'source_type' => 'work_order',
            'source_id' => $workOrder->id,
            'status' => 'issued',
            'reason' => 'Bahan kunjungan',
        ]);
        InventoryRequestItem::forceCreate([
            'company_id' => $this->company->id,
            'inventory_request_id' => $handover->id,
            'product_id' => $fromWarehouse->id,
            'quantity_requested' => 1,
            'quantity_approved' => 1,
            'quantity_issued' => 1,
        ]);

        $this->assertSame(['Material terpakai dikurangi dari stok: 1 barang.'], SpkApprovalImpact::for($workOrder));

        $this->approve($workOrder);
        $this->assertSame(8.0, $this->stockOf($fromSite), 'the cable came out of stock now');
        $this->assertSame(10.0, $this->stockOf($fromWarehouse), 'the connector left the warehouse at the handover, not here');
    }

    public function test_activation_is_named_only_while_the_subscription_is_pending(): void
    {
        $workOrder = $this->submitted(subscriptionStatus: 'active');

        $impact = SpkApprovalImpact::for($workOrder);

        $this->assertCount(1, $impact);
        $this->assertStringStartsWith('Tagihan pemasangan', $impact[0]);
    }

    public function test_the_pppoe_account_is_named_only_when_approval_will_make_it(): void
    {
        $workOrder = $this->submitted(radius: true);
        $customer = Customer::query()->findOrFail($workOrder->customer_id);
        $line = "Akun PPPoE dibuat dari kode pelanggan {$customer->code}.";

        $this->assertContains($line, SpkApprovalImpact::for($workOrder));

        $this->approve($workOrder);
        $this->assertNotNull(NetworkAccount::query()->where('subscription_id', $workOrder->subscription_id)->first(), 'the approval made the account the line promised');
    }

    public function test_the_pppoe_account_is_not_named_without_radius_an_account_or_a_radius_profile(): void
    {
        $withoutRadius = $this->submitted();
        $this->assertCount(2, SpkApprovalImpact::for($withoutRadius), 'no RADIUS for the company: no account is made');

        $withoutProfile = $this->submitted(radius: true, radiusProfile: null);
        $this->assertCount(2, SpkApprovalImpact::for($withoutProfile), 'create() refuses a speed profile without a RADIUS name and the account is skipped');

        $assigned = $this->submitted(radius: true);
        $this->networkAccountFor(ServiceSubscription::query()->findOrFail($assigned->subscription_id));
        $this->assertCount(2, SpkApprovalImpact::for($assigned), 'the account was made at the assignment: approval makes none');
    }

    public function test_impact_absent_without_approve_permission(): void
    {
        $workOrder = $this->submitted();
        $viewer = User::factory()->create(['company_id' => $this->company->id, 'email_verified_at' => now(), 'is_active' => true]);
        $viewer->givePermissionTo('spk.view');

        $this->actingAs($viewer)->get(route('admin.spk.show', $workOrder))->assertOk()->assertInertia(fn (Assert $page) => $page->where('approvalImpact', null));

        $this->actingAs($this->approver)->get(route('admin.spk.show', $workOrder))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('approvalImpact', 2)->where('approvalImpact.1', 'Tagihan pemasangan Rp 277.500 dibuat sebagai draf. Kirim dari halaman tagihan.'));
    }

    public function test_impact_is_only_for_an_spk_that_awaits_approval(): void
    {
        $workOrder = $this->submitted();
        $workOrder->forceFill(['status' => 'in_progress'])->save();

        $this->actingAs($this->approver)->get(route('admin.spk.show', $workOrder))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('approvalImpact', null));
    }
}
