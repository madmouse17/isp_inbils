<?php

namespace Tests\Feature\Billing;

use App\Actions\CreateInvoiceFromSpkAction;
use App\Models\Core\Company;
use App\Models\Core\Customer;
use App\Models\Core\Location;
use App\Models\Core\ServiceSubscription;
use App\Models\User;
use App\Services\Core\CompanyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Modules\Billing\Database\Factories\InvoiceFactory;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Notifications\InstallationInvoiceDraftNotification;
use Modules\Billing\Services\BillingService;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\Stock;
use Modules\SPK\Database\Factories\WorkOrderFactory;
use Modules\SPK\Models\WorkOrder;
use Modules\SPK\Models\WorkOrderItem;
use Modules\SPK\Services\SpkService;
use Spatie\Permission\Models\Permission;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * K7 and K17: the approval of an installation SPK bills it. The invoice is a draft the billing staff are told about, or
 * sent at once when the company sends installation invoices by itself; the materials the technician used are part of the
 * fee unless the company bills them as lines.
 */
class InstallationInvoicePolicyTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $approver;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();

        foreach (['spk.approve', 'billing.send', 'billing.view', 'dashboard.view'] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
    }

    /**
     * An SPK in review with its evidence and, when asked, two metres of a consumable the company sells at 25.000.
     *
     * @param  array<string, mixed>  $settings  company settings
     * @param  array<string, mixed>  $subscription  subscription columns
     */
    private function submitted(array $settings = [], array $subscription = [], bool $withMaterial = false, string $type = 'installation'): WorkOrder
    {
        $this->company = Company::factory()->create(['settings' => ['tax_ppn_rate' => 11, ...$settings]]);
        $creator = User::factory()->create(['company_id' => $this->company->id]);
        $this->approver = User::factory()->create(['company_id' => $this->company->id]);
        $this->approver->givePermissionTo('spk.approve');
        $this->actingAs($creator);

        $pop = Location::factory()->create(['company_id' => $this->company->id]);
        $customer = Customer::factory()->create(['company_id' => $this->company->id]);
        $planned = ServiceSubscription::factory()->create([
            'company_id' => $this->company->id,
            'customer_id' => $customer->id,
            'status' => 'pending',
            'otc_installation_fee' => 250000,
            ...$subscription,
        ]);
        $workOrder = WorkOrderFactory::new()->create([
            'company_id' => $this->company->id,
            'type' => $type,
            'status' => 'waiting_review',
            'customer_id' => $customer->id,
            'subscription_id' => $planned->id,
            'location_id' => $pop->id,
            'assigned_to' => null,
            'created_by' => $creator->id,
        ]);
        $workOrder->addMediaFromString('evidence')
            ->usingFileName('proof.jpg')
            ->withCustomProperties(['company_id' => $this->company->id, 'type' => 'photo', 'uploaded_by' => $creator->id])
            ->toMediaCollection('evidence', 'public');

        if ($withMaterial) {
            $cable = Product::factory()->create(['company_id' => $this->company->id, 'type' => 'consumable', 'name' => 'Kabel Drop', 'sell_price' => 25000]);
            Stock::create(['company_id' => $this->company->id, 'product_id' => $cable->id, 'location_id' => $pop->id, 'quantity' => 10, 'reserved_quantity' => 0]);
            WorkOrderItem::create(['company_id' => $this->company->id, 'work_order_id' => $workOrder->id, 'product_id' => $cable->id, 'quantity_used' => 2]);
        }

        return $workOrder;
    }

    private function approve(WorkOrder $workOrder): ?Invoice
    {
        $this->actingAs($this->approver);
        SpkService::approve($workOrder);

        return Invoice::query()->where('work_order_id', $workOrder->id)->first();
    }

    /** @param  list<string>  $permissions */
    private function staffWith(array $permissions): User
    {
        $user = User::factory()->create(['company_id' => $this->company->id, 'email_verified_at' => now(), 'is_active' => true]);
        $user->givePermissionTo($permissions);
        $this->grantPermissions($user, ['app.web']);

        return $user;
    }

    public function test_installation_approval_drafts_invoice_and_notifies_billing_send_holders(): void
    {
        $workOrder = $this->submitted();
        $biller = $this->staffWith(['billing.send']);
        $bystander = $this->staffWith(['billing.view']);

        $invoice = $this->approve($workOrder);

        $this->assertNotNull($invoice);
        $this->assertSame(['draft', 'spk', 'one_time'], [$invoice->status, $invoice->source, $invoice->type]);
        $this->assertSame(['250000.00', '27500.00', '277500.00'], [$invoice->subtotal, $invoice->tax_amount, $invoice->total]);
        Notification::assertSentTo($biller, InstallationInvoiceDraftNotification::class, fn (InstallationInvoiceDraftNotification $notice): bool => $notice->invoiceId === $invoice->id && $notice->companyId === $this->company->id);
        Notification::assertNotSentTo($bystander, InstallationInvoiceDraftNotification::class);

        // What the bell renders (NotificationResource reads exactly these keys).
        $this->assertSame([
            'type' => 'invoice_draft_pending',
            'invoice_id' => $invoice->public_id,
            'invoice_number' => $invoice->number,
            'customer_name' => Customer::query()->findOrFail($invoice->customer_id)->name,
            'total' => '277500.00',
            'company_id' => $this->company->id,
        ], (new InstallationInvoiceDraftNotification($this->company->id, $invoice->id))->toArray($biller));
    }

    public function test_auto_send_sends_on_approval(): void
    {
        $workOrder = $this->submitted(['auto_send_installation_invoice' => true]);
        $biller = $this->staffWith(['billing.send']);

        $invoice = $this->approve($workOrder);

        $this->assertSame('sent', $invoice->status);
        $this->assertNotNull($invoice->sent_at);
        $this->assertNotNull($invoice->customer_name_snapshot, 'sending freezes the party');
        Notification::assertNotSentTo($biller, InstallationInvoiceDraftNotification::class);
    }

    public function test_materials_not_billed_by_default(): void
    {
        $invoice = $this->approve($this->submitted(withMaterial: true));

        $this->assertSame(['Biaya pemasangan'], $invoice->items()->pluck('description')->all());
        $this->assertSame('277500.00', $invoice->total);
    }

    public function test_materials_billed_as_bahan_terpakai_when_enabled(): void
    {
        $invoice = $this->approve($this->submitted(['bill_installation_materials' => true], withMaterial: true));

        $lines = $invoice->items()->orderBy('id')->get(['description', 'quantity', 'unit_price', 'tax_rate', 'line_total'])->map->only(['description', 'quantity', 'unit_price', 'tax_rate', 'line_total'])->all();
        $this->assertSame([
            ['description' => 'Bahan terpakai: Kabel Drop', 'quantity' => '2.00', 'unit_price' => '25000.00', 'tax_rate' => '11.00', 'line_total' => '50000.00'],
            ['description' => 'Biaya pemasangan', 'quantity' => '1.00', 'unit_price' => '250000.00', 'tax_rate' => '11.00', 'line_total' => '250000.00'],
        ], $lines);
        $this->assertSame(['300000.00', '33000.00', '333000.00'], [$invoice->subtotal, $invoice->tax_amount, $invoice->total]);
    }

    public function test_fee_line_reads_biaya_pemasangan_and_only_for_installation(): void
    {
        $installation = $this->submitted();
        $maintenance = WorkOrderFactory::new()->create([
            'company_id' => $this->company->id,
            'type' => 'maintenance',
            'status' => 'completed',
            'customer_id' => $installation->customer_id,
            'subscription_id' => $installation->subscription_id,
        ]);

        $lines = CreateInvoiceFromSpkAction::linesFor($installation);

        $this->assertCount(1, $lines);
        $this->assertSame('Biaya pemasangan', $lines[0]['description']);
        $this->assertNull($lines[0]['product_id']);
        $this->assertSame(['1.00', '250000.00', 11.0, '250000.00', '27500.00'], [$lines[0]['quantity'], $lines[0]['unit_price'], $lines[0]['tax_rate'], $lines[0]['line_total'], $lines[0]['tax_amount']]);
        $this->assertSame([], CreateInvoiceFromSpkAction::linesFor($maintenance), 'a visit that is not an installation has no installation fee');
    }

    public function test_no_billable_line_creates_no_invoice(): void
    {
        $workOrder = $this->submitted(subscription: ['otc_installation_fee' => 0], withMaterial: true);
        $biller = $this->staffWith(['billing.send']);

        $this->assertNull($this->approve($workOrder));

        $this->assertSame('completed', $workOrder->fresh()->status);
        $this->assertSame(0, Invoice::query()->count());
        Notification::assertNotSentTo($biller, InstallationInvoiceDraftNotification::class);

        try {
            BillingService::createFromSpk($workOrder->id);
            $this->fail('an SPK that bills nothing was invoiced by hand');
        } catch (HttpException $exception) {
            $this->assertSame(422, $exception->getStatusCode());
            $this->assertSame('SPK ini tidak punya biaya atau bahan yang bisa ditagihkan.', $exception->getMessage());
        }
    }

    public function test_a_maintenance_approval_is_not_billed_on_its_own(): void
    {
        $workOrder = $this->submitted(['bill_installation_materials' => true], withMaterial: true, type: 'maintenance');

        $this->assertNull($this->approve($workOrder));

        // By hand it still can be: the company bills the materials of the visit.
        $invoice = BillingService::createFromSpk($workOrder->id);
        $this->assertSame(['Bahan terpakai: Kabel Drop'], $invoice->items()->pluck('description')->all());
        $this->assertSame('draft', $invoice->status);
    }

    public function test_lines_for_is_pure(): void
    {
        $workOrder = $this->submitted(['bill_installation_materials' => true], withMaterial: true);

        DB::enableQueryLog();
        $first = CreateInvoiceFromSpkAction::linesFor($workOrder);
        $second = CreateInvoiceFromSpkAction::linesFor($workOrder);
        $writes = collect(DB::getQueryLog())->pluck('query')->reject(fn (string $sql): bool => str_starts_with(strtolower($sql), 'select'));

        $this->assertCount(2, $first);
        $this->assertSame($first, $second);
        $this->assertSame([], $writes->all(), 'building the lines writes nothing');
        $this->assertSame(0, Invoice::query()->count());
    }

    public function test_draft_card_counts_drafts_for_billing_send_holders(): void
    {
        $this->submitted();
        $biller = $this->staffWith(['dashboard.view', 'billing.view', 'billing.send']);
        $viewer = $this->staffWith(['dashboard.view', 'billing.view']);
        $sender = $this->staffWith(['dashboard.view', 'billing.send']);
        $card = fn (User $user): ?array => collect($this->actingAs($user)->get(route('admin.dashboard'))->assertOk()->inertiaProps('sections') ?? [])->firstWhere('key', 'draft_invoices');

        $this->assertSame(0, $card($biller)['count']);
        $this->assertSame('neutral', $card($biller)['severity']);

        foreach ([100000, 200000] as $total) {
            InvoiceFactory::new()->create(['company_id' => $this->company->id, 'status' => 'draft', 'total' => $total]);
        }
        InvoiceFactory::new()->create(['company_id' => $this->company->id, 'status' => 'sent', 'total' => 999999]);
        InvoiceFactory::new()->create(['company_id' => Company::factory()->create()->id, 'status' => 'draft', 'total' => 555555]);

        $section = $card($biller);
        $this->assertSame(2, $section['count']);
        $this->assertSame('300000.00', $section['total']);
        $this->assertSame(['finance', 'Tagihan draf menunggu dikirim', 'warning'], [$section['group'], $section['title'], $section['severity']]);
        $this->assertSame(route('admin.invoices.index', ['status' => 'draft']), $section['href']);
        $this->assertNull($card($viewer), 'billing.view alone has nothing to send');
        $this->assertNull($card($sender), 'the card opens the invoice list, which needs billing.view');
        $this->assertSame($this->company->id, CompanyService::idFor($biller));
    }
}
