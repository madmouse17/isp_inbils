<?php

namespace Tests\Feature\SPK;

use App\Actions\CreateInvoiceFromSpkAction;
use App\Models\Core\Company;
use App\Models\Core\ServiceSubscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Billing\Models\Invoice;
use Modules\NetworkAsset\Models\NetworkAccount;
use Modules\SPK\Database\Factories\WorkOrderFactory;
use Modules\SPK\Models\WorkOrder;
use Tests\TestCase;
use Tests\Traits\BuildsNetworkFixtures;
use Tests\Traits\BuildsSubscriptionBilling;
use Tests\Traits\InteractsWithRadius;
use Tests\Traits\WithoutAppOnlyRedirect;

/**
 * F4-U18: a completed installation tells the office what is left to do (send the invoice, make the network account),
 * and each part only reaches the people who may see or act on it.
 */
class WorkOrderNextStepsTest extends TestCase
{
    use BuildsNetworkFixtures;
    use BuildsSubscriptionBilling;
    use InteractsWithRadius;
    use RefreshDatabase;
    use WithoutAppOnlyRedirect;

    private Company $company;

    private ServiceSubscription $subscription;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        Queue::fake();
    }

    /** A completed SPK of the type, on an active subscription that carries a Rp 250.000 installation fee. */
    private function completed(string $type = 'installation', int $fee = 250000, bool $invoiced = true, bool $radius = false): WorkOrder
    {
        $this->company = Company::factory()->create(['settings' => ['tax_ppn_rate' => 11]]);
        $creator = User::factory()->create(['company_id' => $this->company->id]);
        $this->actingAs($creator);

        if ($radius) {
            $this->fakeRadius();
        }

        $this->subscription = $this->subscriptionIn($this->company, 'active');
        $this->subscription->forceFill(['otc_installation_fee' => $fee])->save();

        $workOrder = WorkOrderFactory::new()->create([
            'company_id' => $this->company->id,
            'type' => $type,
            'status' => 'completed',
            'customer_id' => $this->subscription->customer_id,
            'subscription_id' => $this->subscription->id,
            'created_by' => $creator->id,
        ]);

        if ($invoiced) {
            CreateInvoiceFromSpkAction::execute($workOrder->id);
        }

        return $workOrder;
    }

    /** Someone holding exactly these permissions, and the right to open the SPK. */
    private function viewerWith(string ...$permissions): User
    {
        return $this->userWithPermissions($this->company, 'spk.view', ...$permissions);
    }

    /** @return array<string, mixed>|null */
    private function nextSteps(User $viewer, WorkOrder $workOrder): ?array
    {
        return $this->actingAs($viewer)->get(route('admin.spk.show', $workOrder))->assertOk()->inertiaProps('nextSteps');
    }

    public function test_completed_installation_lists_invoice_and_account(): void
    {
        $workOrder = $this->completed(radius: true);
        $invoice = Invoice::query()->where('work_order_id', $workOrder->id)->firstOrFail();
        $account = $this->networkAccountFor($this->subscription);
        $viewer = $this->viewerWith('billing.view', 'billing.send', 'network_asset.account.view', 'network_asset.account.manage');

        $response = $this->actingAs($viewer)->get(route('admin.spk.show', $workOrder))->assertOk();

        $this->assertSame([
            'invoice' => [
                'id' => $invoice->public_id,
                'number' => $invoice->number,
                'status' => 'draft',
                'total' => '277500.00',
                'due_date' => $invoice->due_date->toDateString(),
            ],
            'can_view_invoice' => true,
            'billable' => false,
            'can_send_invoice' => true,
            'can_create_invoice' => false,
            'network_account' => ['username' => $account->username],
            'can_view_network_account' => true,
            'can_manage_network_account' => true,
        ], $response->inertiaProps('nextSteps'));
        $response->assertDontSee('fake-subscriber-secret', false);
    }

    public function test_missing_invoice_offers_create_with_permission(): void
    {
        $workOrder = $this->completed(invoiced: false);

        $biller = $this->nextSteps($this->viewerWith('billing.view', 'billing.create'), $workOrder);
        $this->assertNull($biller['invoice']);
        $this->assertTrue($biller['billable'], 'the fee is still owed');
        $this->assertTrue($biller['can_create_invoice']);

        $reader = $this->nextSteps($this->viewerWith('billing.view'), $workOrder);
        $this->assertTrue($reader['billable']);
        $this->assertFalse($reader['can_create_invoice'], 'seeing the gap is not making the invoice');
        $this->assertFalse($reader['can_send_invoice']);
    }

    public function test_an_spk_that_bills_nothing_has_no_invoice_to_make(): void
    {
        $workOrder = $this->completed(fee: 0, invoiced: false);

        $steps = $this->nextSteps($this->viewerWith('billing.view', 'billing.create'), $workOrder);

        $this->assertSame([null, false, false], [$steps['invoice'], $steps['billable'], $steps['can_create_invoice']]);
    }

    public function test_a_cancelled_invoice_is_shown_as_it_is_and_never_offered_again(): void
    {
        $workOrder = $this->completed();
        Invoice::query()->where('work_order_id', $workOrder->id)->update(['status' => 'cancelled']);

        $steps = $this->nextSteps($this->viewerWith('billing.view', 'billing.create'), $workOrder);

        $this->assertSame('cancelled', $steps['invoice']['status']);
        $this->assertFalse($steps['billable'], 'create-from-spk refuses an SPK that was billed once');
        $this->assertFalse($steps['can_create_invoice']);
    }

    public function test_other_spks_and_viewers_without_a_stake_get_nothing(): void
    {
        $maintenance = $this->completed(type: 'maintenance');
        $this->assertNull($this->nextSteps($this->viewerWith('billing.view', 'network_asset.account.view'), $maintenance), 'only an installation has these steps');

        $installation = $this->completed();
        $this->assertNull($this->nextSteps($this->viewerWith(), $installation), 'a viewer of the SPK alone sees neither the invoice nor the account');

        $inReview = $this->completed();
        $inReview->forceFill(['status' => 'waiting_review'])->save();
        $this->assertNull($this->nextSteps($this->viewerWith('billing.view'), $inReview), 'not completed yet');
    }

    public function test_the_network_account_only_reaches_those_who_may_open_it(): void
    {
        $workOrder = $this->completed(radius: true);
        $this->networkAccountFor($this->subscription);

        $biller = $this->nextSteps($this->viewerWith('billing.view'), $workOrder);

        $this->assertNull($biller['network_account'], 'the username is shown to those who may open the account');
        $this->assertFalse($biller['can_view_network_account']);
        $this->assertFalse($biller['can_manage_network_account']);
    }

    public function test_the_network_row_is_no_step_without_radius_and_without_an_account(): void
    {
        $workOrder = $this->completed();
        $viewer = $this->viewerWith('billing.view', 'network_asset.account.view', 'network_asset.account.manage');

        $steps = $this->nextSteps($viewer, $workOrder);

        $this->assertFalse($steps['can_view_network_account'], 'nothing would apply an account the company has no RADIUS for');
        $this->assertFalse($steps['can_manage_network_account']);
    }

    public function test_the_missing_network_account_is_a_step_when_the_company_serves_radius(): void
    {
        $workOrder = $this->completed(radius: true);
        $viewer = $this->viewerWith('network_asset.account.view', 'network_asset.account.manage');

        $steps = $this->nextSteps($viewer, $workOrder);

        $this->assertNull(NetworkAccount::query()->where('subscription_id', $workOrder->subscription_id)->first());
        $this->assertNull($steps['network_account']);
        $this->assertTrue($steps['can_view_network_account']);
        $this->assertTrue($steps['can_manage_network_account']);
        $this->assertFalse($steps['can_view_invoice'], 'the invoice rows stay out for someone who may not see invoices');
    }

    public function test_without_a_subscription_there_is_no_network_row_and_no_fee(): void
    {
        $workOrder = $this->completed(invoiced: false, radius: true);
        $workOrder->forceFill(['subscription_id' => null])->save();

        $steps = $this->nextSteps($this->viewerWith('billing.view', 'network_asset.account.view'), $workOrder);

        $this->assertFalse($steps['can_view_network_account']);
        $this->assertFalse($steps['billable'], 'with no subscription there is no fee to bill');
    }

    public function test_the_page_renders_for_a_viewer_with_the_card(): void
    {
        $workOrder = $this->completed();

        $this->actingAs($this->viewerWith('billing.view'))->get(route('admin.spk.show', $workOrder))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Admin/SPK/Show')->has('nextSteps.invoice')->where('nextSteps.can_view_invoice', true));
    }
}
