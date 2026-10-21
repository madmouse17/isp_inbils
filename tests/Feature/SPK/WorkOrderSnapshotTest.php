<?php

namespace Tests\Feature\SPK;

use App\Actions\CreateInvoiceFromSpkAction;
use App\Models\Core\Company;
use App\Models\Core\Customer;
use App\Models\Core\Location;
use App\Models\Core\OrganizationUnit;
use App\Models\Core\ServiceSubscription;
use App\Models\User;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\InvoiceItem;
use Modules\Inventory\Models\Product;
use Modules\Service\Database\Factories\ServicePackageFactory;
use Modules\SPK\Database\Factories\WorkOrderFactory;
use Modules\SPK\Models\WorkOrder;
use Modules\SPK\Models\WorkOrderAssignment;
use Modules\SPK\Models\WorkOrderItem;
use Modules\SPK\Notifications\TechnicianEnRouteNotification;
use Modules\SPK\Notifications\VisitCompletedNotification;
use Modules\SPK\Services\SpkService;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;
use Tests\Traits\CreatesCompanyUser;
use Tests\Traits\InteractsWithMobileApi;

/**
 * A completed SPK keeps the technician, the location code and the technician's unit as they were when the manager
 * approved it (spec master-lifecycle D4): a rename or a move afterwards never reaches it, a visit still under way shows
 * the live values, and a deleted SPK comes back. Its relations keep a deleted customer, subscription, location or
 * product for the history pages, but a deleted customer or subscription takes no installation, no activation, no
 * message and no installation fee.
 */
class WorkOrderSnapshotTest extends TestCase
{
    use CreatesCompanyUser;
    use InteractsWithMobileApi;
    use RefreshDatabase;

    private User $admin;

    private User $creator;

    private User $technician;

    private OrganizationUnit $unit;

    private Location $pop;

    protected function setUp(): void
    {
        parent::setUp();

        // The messages that approving sends are not under test here.
        Notification::fake();
        Storage::fake('public');

        $this->admin = $this->createCompanyUser();
        $this->creator = User::factory()->create(['company_id' => $this->admin->company_id]);
        $this->unit = OrganizationUnit::factory()->create(['company_id' => $this->admin->company_id, 'name' => 'Tim Pasang']);
        $this->technician = $this->technicianLogin($this->admin->company, ['name' => 'Budi Teknisi']);
        $this->technician->employeeProfile()->update(['organization_id' => $this->unit->id]);
        $this->pop = Location::factory()->create(['company_id' => $this->admin->company_id, 'code' => 'POP-01']);
    }

    /** @param  array<string, mixed>  $attributes */
    private function workOrder(string $status, array $attributes = []): WorkOrder
    {
        return WorkOrderFactory::new()->create([
            'company_id' => $this->admin->company_id,
            'type' => 'maintenance',
            'status' => $status,
            'location_id' => $this->pop->id,
            'assigned_to' => $this->technician->id,
            'created_by' => $this->creator->id,
            ...$attributes,
        ]);
    }

    /** An SPK in review, with its evidence. */
    private function submitted(array $attributes = []): WorkOrder
    {
        $workOrder = $this->workOrder('waiting_review', $attributes);
        $workOrder->addMediaFromString('evidence')
            ->usingFileName('proof.jpg')
            ->withCustomProperties(['company_id' => $workOrder->company_id, 'type' => 'photo', 'uploaded_by' => $this->technician->id])
            ->toMediaCollection('evidence', 'public');

        return $workOrder;
    }

    /** An SPK the manager approves now. */
    private function approved(array $attributes = []): WorkOrder
    {
        $workOrder = $this->submitted($attributes);

        $this->actingAs($this->admin);
        SpkService::approve($workOrder);

        return $workOrder->fresh();
    }

    /** @return array<string, mixed> */
    private function shown(WorkOrder $workOrder): array
    {
        return $this->actingAs($this->admin)->get(route('admin.spk.show', $workOrder))->assertOk()->viewData('page')['props']['workOrder']['data'];
    }

    /**
     * @param  array<string, mixed>  $query
     * @return list<string>
     */
    private function listedCodes(array $query = []): array
    {
        $rows = $this->actingAs($this->admin)->get(route('admin.spk.index', $query))->assertOk()->viewData('page')['props']['workOrders']['data'];
        $codes = array_column($rows, 'code');
        sort($codes);

        return $codes;
    }

    /**
     * A customer with a login and a subscription, and the token of its app.
     *
     * @param  array<string, mixed>  $columns  subscription columns
     * @return array{0: Customer, 1: ServiceSubscription, 2: string}
     */
    private function customerApp(string $status = 'active', array $columns = []): array
    {
        [$user, $customer] = $this->customerLogin($this->admin->company);
        $subscription = ServiceSubscription::factory()->create([
            'company_id' => $customer->company_id,
            'customer_id' => $customer->id,
            'service_package_id' => ServicePackageFactory::new()->create(['company_id' => $customer->company_id])->id,
            'status' => $status,
            'activation_date' => $status === 'active' ? '2025-01-01' : null,
            ...$columns,
        ]);

        return [$customer, $subscription, $this->issueToken($user, 'customer')];
    }

    public function test_approve_freezes_the_technician_the_location_and_the_unit(): void
    {
        $workOrder = $this->approved();

        $this->assertSame('completed', $workOrder->status);
        $this->assertSame(
            ['Budi Teknisi', 'POP-01', $this->unit->id],
            [$workOrder->technician_name_snapshot, $workOrder->location_code_snapshot, $workOrder->organization_unit_id],
        );
    }

    public function test_approving_an_spk_without_a_technician_or_a_location_freezes_nothing_to_name(): void
    {
        $workOrder = $this->approved(['assigned_to' => null, 'location_id' => null]);

        $this->assertSame('completed', $workOrder->status);
        $this->assertSame([null, null, null], [$workOrder->technician_name_snapshot, $workOrder->location_code_snapshot, $workOrder->organization_unit_id]);
    }

    public function test_the_unit_is_the_one_of_a_technician_whose_profile_was_deleted_before_the_approval(): void
    {
        $workOrder = $this->submitted();
        $this->technician->employeeProfile()->delete();

        $this->actingAs($this->admin);
        SpkService::approve($workOrder);

        $this->assertSame($this->unit->id, $workOrder->fresh()->organization_unit_id);
    }

    public function test_a_completed_spk_keeps_its_snapshot_when_the_technician_is_renamed_and_moved(): void
    {
        [$customer, $subscription, $token] = $this->customerApp();
        $workOrder = $this->approved(['customer_id' => $customer->id, 'subscription_id' => $subscription->id]);

        $otherUnit = OrganizationUnit::factory()->create(['company_id' => $this->admin->company_id, 'name' => 'Tim Perawatan']);
        $this->technician->update(['name' => 'Candra Baru']);
        $this->technician->employeeProfile()->update(['organization_id' => $otherUnit->id]);
        $this->pop->update(['code' => 'POP-99']);

        $this->assertSame($this->unit->id, $workOrder->fresh()->organization_unit_id, 'the unit stays the one at completion');

        $data = $this->shown($workOrder);
        $this->assertSame('Budi Teknisi', $data['technician_name_snapshot']);
        $this->assertSame('POP-01', $data['location_code_snapshot']);
        $this->assertSame('Candra Baru', $data['assignee']['name'], 'the live technician is still there for a page that wants it');

        $this->api('GET', "customer/work-orders/{$workOrder->public_id}", token: $token)
            ->assertOk()
            ->assertJsonPath('data.technician.first_name', 'Budi');
    }

    public function test_a_visit_under_way_shows_the_live_values(): void
    {
        [$customer, $subscription, $token] = $this->customerApp();
        $workOrder = $this->workOrder('assigned', ['customer_id' => $customer->id, 'subscription_id' => $subscription->id]);

        $this->technician->update(['name' => 'Candra Baru']);
        $this->pop->update(['code' => 'POP-99']);

        $data = $this->shown($workOrder);
        $this->assertSame([null, null], [$data['technician_name_snapshot'], $data['location_code_snapshot']]);
        $this->assertSame(['Candra Baru', 'POP-99'], [$data['assignee']['name'], $data['location']['code']]);

        $this->api('GET', "customer/work-orders/{$workOrder->public_id}", token: $token)
            ->assertOk()
            ->assertJsonPath('data.technician.first_name', 'Candra');
    }

    public function test_the_export_names_the_technician_of_a_completed_spk_as_it_was(): void
    {
        $this->approved(['code' => 'SPK-EXPORT-DONE']);
        $this->workOrder('assigned', ['code' => 'SPK-EXPORT-LIVE']);
        $this->technician->update(['name' => 'Candra Baru']);

        $lines = preg_split('/\R/', $this->actingAs($this->admin)->get(route('admin.spk.export', ['format' => 'csv']))->assertOk()->streamedContent());
        $rowOf = fn (string $code): string => collect($lines)->first(fn (string $line): bool => str_contains($line, $code));

        $this->assertStringContainsString('Budi Teknisi', $rowOf('SPK-EXPORT-DONE'));
        $this->assertStringContainsString('Candra Baru', $rowOf('SPK-EXPORT-LIVE'));
    }

    public function test_status_filter_values(): void
    {
        $this->assertSame(
            ['draft', 'generated', 'assigned', 'in_progress', 'waiting_review', 'completed', 'rejected', 'cancelled'],
            WorkOrder::STATUSES,
        );

        $this->workOrder('draft', ['code' => 'SPK-F-DRAFT']);
        $this->workOrder('completed', ['code' => 'SPK-F-DONE']);
        $this->workOrder('cancelled', ['code' => 'SPK-F-GONE'])->delete();

        $this->assertSame(['SPK-F-DONE', 'SPK-F-DRAFT'], $this->listedCodes(), 'deleted rows are hidden by default');
        $this->assertSame(['SPK-F-DONE'], $this->listedCodes(['status' => 'completed']));
        $this->assertSame(['SPK-F-GONE'], $this->listedCodes(['status' => 'dihapus']));
        $this->assertSame(['SPK-F-DONE', 'SPK-F-DRAFT', 'SPK-F-GONE'], $this->listedCodes(['status' => 'semua']));
        $this->assertSame(['SPK-F-DONE', 'SPK-F-DRAFT'], $this->listedCodes(['status' => 'unknown']), 'an unknown value is the default view');

        $page = $this->get(route('admin.spk.index', ['status' => 'dihapus']))->assertOk()->viewData('page')['props'];
        $this->assertNotNull($page['workOrders']['data'][0]['deleted_at']);
        $this->assertSame('dihapus', $page['filters']['status']);
        $this->assertTrue($page['can']['update']);
        $this->assertTrue($page['can']['delete']);
    }

    public function test_a_cancelled_spk_can_be_deleted_and_restored(): void
    {
        $workOrder = $this->workOrder('cancelled', ['code' => 'SPK-RESTORE']);

        $this->actingAs($this->admin)->delete(route('admin.spk.destroy', $workOrder))->assertRedirect();
        $this->assertSoftDeleted($workOrder);
        $this->assertSame([], $this->listedCodes());
        $this->assertSame(['SPK-RESTORE'], $this->listedCodes(['status' => 'dihapus']));

        $this->post(route('admin.spk.restore', $workOrder->public_id))
            ->assertRedirect()
            ->assertSessionHas('success', 'SPK dipulihkan.');

        $this->assertNotSoftDeleted($workOrder);
        $this->assertSame('cancelled', $workOrder->fresh()->status);
        $this->assertSame(['SPK-RESTORE'], $this->listedCodes(['status' => 'cancelled']));
        $this->assertSame([], $this->listedCodes(['status' => 'dihapus']));
    }

    public function test_restore_needs_the_delete_permission_and_stays_inside_the_company(): void
    {
        $workOrder = $this->workOrder('cancelled');
        $workOrder->delete();

        $other = Company::factory()->create(['is_active' => true]);
        $foreign = WorkOrderFactory::new()->create(['company_id' => $other->id, 'status' => 'cancelled', 'created_by' => $this->creator->id]);
        $foreign->delete();

        $planner = User::factory()->create(['company_id' => $this->admin->company_id, 'email_verified_at' => now(), 'is_active' => true]);
        $this->grantPermissions($planner, ['app.web', 'spk.view', 'spk.update']);

        $this->actingAs($planner)->post(route('admin.spk.restore', $workOrder->public_id))->assertForbidden();
        $this->assertSoftDeleted($workOrder);

        $this->actingAs($this->admin)->post(route('admin.spk.restore', $foreign->public_id))->assertNotFound();
        $this->assertSoftDeleted($foreign);
    }

    public function test_history_relations_keep_their_deleted_parents(): void
    {
        $relations = [
            WorkOrder::class => ['customer', 'subscription', 'location', 'ticket', 'organizationUnit'],
            WorkOrderItem::class => ['workOrder', 'product', 'networkAsset'],
            WorkOrderAssignment::class => ['workOrder'],
        ];

        foreach ($relations as $model => $names) {
            foreach ($names as $name) {
                $this->assertContains(
                    SoftDeletingScope::class,
                    (new $model())->{$name}()->getQuery()->removedScopes(),
                    $model.'::'.$name.' must still resolve a deleted parent',
                );
            }
        }

        [$customer, $subscription] = $this->customerApp();
        $workOrder = $this->approved(['customer_id' => $customer->id, 'subscription_id' => $subscription->id]);
        $subscription->delete();
        $customer->delete();
        $this->pop->delete();
        $this->unit->delete();

        $reloaded = WorkOrder::query()->findOrFail($workOrder->id);
        $this->assertSame($customer->name, $reloaded->customer?->name);
        $this->assertSame($subscription->code, $reloaded->subscription?->code);
        $this->assertSame('POP-01', $reloaded->location?->code);
        $this->assertSame('Tim Pasang', $reloaded->organizationUnit?->name);

        $data = $this->shown($workOrder);
        $this->assertSame($customer->name, $data['customer']['name']);
        $this->assertSame('POP-01', $data['location_code_snapshot']);
    }

    public function test_a_deleted_customer_or_subscription_is_not_told_about_an_approved_visit(): void
    {
        [$customer, $subscription] = $this->customerApp();
        $this->approved(['customer_id' => $customer->id, 'subscription_id' => $subscription->id]);
        Notification::assertSentTo($customer, VisitCompletedNotification::class);

        [$deletedCustomer, $subscriptionOfDeletedCustomer] = $this->customerApp();
        $deletedCustomer->delete();
        $this->approved(['customer_id' => $deletedCustomer->id, 'subscription_id' => $subscriptionOfDeletedCustomer->id]);

        [$owner, $deletedSubscription] = $this->customerApp();
        $deletedSubscription->delete();
        $this->approved(['customer_id' => $owner->id, 'subscription_id' => $deletedSubscription->id]);

        Notification::assertNotSentTo($deletedCustomer, VisitCompletedNotification::class);
        Notification::assertNotSentTo($owner, VisitCompletedNotification::class);
    }

    public function test_a_deleted_customer_is_not_told_that_the_technician_is_on_the_way(): void
    {
        [$customer, $subscription] = $this->customerApp();
        $live = $this->workOrder('assigned', ['customer_id' => $customer->id, 'subscription_id' => $subscription->id]);
        [$deletedCustomer, $subscriptionOfDeletedCustomer] = $this->customerApp();
        $deletedCustomer->delete();
        $gone = $this->workOrder('assigned', ['customer_id' => $deletedCustomer->id, 'subscription_id' => $subscriptionOfDeletedCustomer->id]);
        $token = $this->issueToken($this->technician, 'technician');

        $this->api('POST', "technician/work-orders/{$live->public_id}/en-route", token: $token)->assertOk();
        $this->api('POST', "technician/work-orders/{$gone->public_id}/en-route", token: $token)->assertOk();

        Notification::assertSentTo($customer, TechnicianEnRouteNotification::class);
        Notification::assertNotSentTo($deletedCustomer, TechnicianEnRouteNotification::class);
    }

    /** @return array<string, array{string}> */
    public static function deletedParents(): array
    {
        return ['customer' => ['customer'], 'subscription' => ['subscription']];
    }

    #[DataProvider('deletedParents')]
    public function test_an_installation_for_a_deleted_customer_or_subscription_is_still_refused(string $deleted): void
    {
        [$customer, $subscription] = $this->customerApp('pending');
        $workOrder = $this->submitted(['type' => 'installation', 'customer_id' => $customer->id, 'subscription_id' => $subscription->id]);
        ($deleted === 'customer' ? $customer : $subscription)->delete();

        $this->actingAs($this->admin);

        try {
            SpkService::approve($workOrder);
            $this->fail("an installation of a deleted {$deleted} was approved");
        } catch (HttpException $exception) {
            $this->assertSame(422, $exception->getStatusCode());
            $this->assertSame('SPK instalasi wajib memiliki pelanggan dan langganan.', $exception->getMessage());
        }

        $workOrder = $workOrder->fresh();
        $this->assertSame('waiting_review', $workOrder->status);
        $this->assertNull($workOrder->technician_name_snapshot, 'a refused approval freezes nothing');
    }

    public function test_approving_does_not_activate_a_deleted_pending_subscription(): void
    {
        [$customer, $deletedSubscription] = $this->customerApp('pending');
        $deletedSubscription->delete();
        [$liveCustomer, $liveSubscription] = $this->customerApp('pending');

        $gone = $this->approved(['customer_id' => $customer->id, 'subscription_id' => $deletedSubscription->id]);
        $live = $this->approved(['customer_id' => $liveCustomer->id, 'subscription_id' => $liveSubscription->id]);

        $this->assertSame(['completed', 'completed'], [$gone->status, $live->status]);
        $this->assertSame('pending', ServiceSubscription::withTrashed()->findOrFail($deletedSubscription->id)->status);
        $this->assertSame('active', $liveSubscription->fresh()->status);
    }

    public function test_the_invoice_of_an_spk_bills_the_materials_of_a_product_deleted_later(): void
    {
        [$customer] = $this->customerApp();
        // K7: the materials are part of the installation fee unless the company bills them as lines.
        $this->admin->company->update(['settings' => [...($this->admin->company->settings ?? []), 'bill_installation_materials' => true]]);
        $product = Product::factory()->create(['company_id' => $this->admin->company_id, 'type' => 'consumable', 'name' => 'Kabel Drop', 'sell_price' => 2500]);
        $workOrder = $this->workOrder('completed', ['customer_id' => $customer->id]);
        WorkOrderItem::create(['company_id' => $workOrder->company_id, 'work_order_id' => $workOrder->id, 'product_id' => $product->id, 'quantity_used' => 3]);
        $product->delete();

        $this->actingAs($this->admin);
        $invoice = CreateInvoiceFromSpkAction::execute($workOrder->id);

        $line = InvoiceItem::query()->where('invoice_id', $invoice->id)->where('product_id', $product->id)->sole();
        $this->assertSame(['Bahan terpakai: Kabel Drop', '3.00', '2500.00', '7500.00'], [$line->description, $line->quantity, $line->unit_price, $line->line_total]);
    }

    public function test_the_invoice_of_an_spk_has_no_installation_fee_for_a_deleted_subscription(): void
    {
        [$customer, $deletedSubscription] = $this->customerApp(columns: ['otc_installation_fee' => 50_000]);
        $deletedSubscription->delete();
        [$liveCustomer, $liveSubscription] = $this->customerApp(columns: ['otc_installation_fee' => 50_000]);
        // A deleted subscription bills nothing at all, so its SPK leaves no invoice to count lines on.
        $fees = fn (?Invoice $invoice): int => $invoice === null ? 0 : InvoiceItem::query()->where('invoice_id', $invoice->id)->where('description', 'Biaya pemasangan')->count();

        $this->actingAs($this->admin);
        $withoutFee = CreateInvoiceFromSpkAction::execute($this->workOrder('completed', ['type' => 'installation', 'customer_id' => $customer->id, 'subscription_id' => $deletedSubscription->id])->id);
        $withFee = CreateInvoiceFromSpkAction::execute($this->workOrder('completed', ['type' => 'installation', 'customer_id' => $liveCustomer->id, 'subscription_id' => $liveSubscription->id])->id);

        $this->assertSame([null, 1], [$withoutFee, $fees($withFee)]);
    }
}
