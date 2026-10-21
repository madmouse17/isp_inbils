<?php

declare(strict_types=1);

namespace Tests\Feature\SPK;

use App\Models\Core\Customer;
use App\Models\Core\Location;
use App\Models\Core\ServiceSubscription;
use App\Models\User;
use App\Support\OptimisticLock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\SPK\Database\Factories\WorkOrderFactory;
use Modules\SPK\Models\WorkOrder;
use Tests\TestCase;
use Tests\Traits\AsksInertiaForProps;
use Tests\Traits\CreatesCompanyUser;

/**
 * F4-D02: "Ubah" opens a page for the SPK that is still a plan. Its pickers search on the server like the create form's,
 * so the page never carries the company's customers, and the SPK page offers the button only while it will open.
 */
class WorkOrderEditPageTest extends TestCase
{
    use AsksInertiaForProps;
    use CreatesCompanyUser;
    use RefreshDatabase;

    private User $admin;

    private int $companyId;

    private Customer $customer;

    private ServiceSubscription $subscription;

    private WorkOrder $workOrder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->admin = $this->createCompanyUser();
        $this->companyId = (int) $this->admin->company_id;
        $this->customer = Customer::factory()->create(['company_id' => $this->companyId, 'name' => 'Sari Wulandari']);
        $this->subscription = ServiceSubscription::factory()->create(['company_id' => $this->companyId, 'customer_id' => $this->customer->id, 'status' => 'pending']);
        $this->workOrder = $this->spk('generated');
    }

    public function test_the_edit_page_carries_the_spk_and_none_of_the_companys_lists(): void
    {
        Customer::factory()->create(['company_id' => $this->companyId, 'name' => 'Pelanggan Lain Sekali']);
        Location::factory()->create(['company_id' => $this->companyId, 'code' => 'LOKASI-LAIN', 'name' => 'Lokasi Lain Sekali']);

        $props = $this->actingAs($this->admin)->get(route('admin.spk.edit', $this->workOrder))->assertOk()->inertiaProps();

        $this->assertSame($this->workOrder->public_id, $props['workOrder']['data']['id']);
        $this->assertSame($this->customer->name, $props['workOrder']['data']['customer']['name']);
        $this->assertSame($this->subscription->code, $props['workOrder']['data']['subscription']['code']);
        foreach (['customers', 'subscriptions', 'locations', 'technicians', 'customerOptions', 'subscriptionOptions', 'locationOptions'] as $absent) {
            $this->assertArrayNotHasKey($absent, $props, "{$absent} is not sent until a picker asks");
        }
        $this->assertStringNotContainsString('Pelanggan Lain Sekali', (string) json_encode($props));
        $this->assertStringNotContainsString('Lokasi Lain Sekali', (string) json_encode($props));
    }

    public function test_the_pickers_answer_a_search_and_the_subscriptions_follow_the_customer(): void
    {
        $other = Customer::factory()->create(['company_id' => $this->companyId, 'name' => 'Pelanggan Lain Sekali']);
        ServiceSubscription::factory()->create(['company_id' => $this->companyId, 'customer_id' => $other->id, 'status' => 'active']);
        $this->actingAs($this->admin);

        $customers = $this->get(route('admin.spk.edit', [$this->workOrder, 'customer_search' => 'Lain Sekali']), $this->partialReload('Admin/SPK/Edit', 'customerOptions'))
            ->assertOk()->json('props.customerOptions');
        $this->assertSame([$other->public_id], array_column($customers, 'value'));

        $narrowed = $this->get(route('admin.spk.edit', [$this->workOrder, 'customer_id' => $this->customer->public_id, 'subscription_search' => 'SUB']), $this->partialReload('Admin/SPK/Edit', 'subscriptionOptions'))
            ->assertOk()->json('props.subscriptionOptions');
        $this->assertSame([$this->subscription->public_id], array_column($narrowed, 'value'), 'only the picked customer\'s subscriptions are offered');
    }

    public function test_the_page_is_for_a_plan_only(): void
    {
        foreach (['assigned', 'in_progress', 'waiting_review', 'completed', 'cancelled'] as $status) {
            $this->actingAs($this->admin)->get(route('admin.spk.edit', $this->spk($status)))
                ->assertRedirect()
                ->assertSessionHas('error', 'SPK hanya bisa diubah saat berstatus draf atau menunggu penugasan.');
        }

        $this->get(route('admin.spk.edit', $this->spk('draft')))->assertOk();
    }

    public function test_the_spk_page_offers_ubah_only_while_the_spk_is_a_plan_and_the_person_may_edit_it(): void
    {
        $viewer = User::factory()->create(['company_id' => $this->companyId, 'is_active' => true, 'email_verified_at' => now()]);
        $this->grantPermissions($viewer, ['app.web', 'spk.view'], $this->companyId);

        foreach (['draft' => true, 'generated' => true, 'assigned' => false, 'in_progress' => false, 'waiting_review' => false, 'completed' => false, 'rejected' => false, 'cancelled' => false] as $status => $offered) {
            $spk = $this->spk($status, 'maintenance');

            $this->assertSame($offered, $this->actingAs($this->admin)->get(route('admin.spk.show', $spk))->assertOk()->inertiaProps('can')['edit'], "admin, {$status}");
            $this->assertFalse($this->actingAs($viewer)->get(route('admin.spk.show', $spk))->assertOk()->inertiaProps('can')['edit'], "viewer, {$status}");
        }
    }

    public function test_the_form_the_page_sends_saves_the_header_with_its_visit_day(): void
    {
        $location = Location::factory()->create(['company_id' => $this->companyId]);
        $visit = now()->addDays(4)->toDateString();

        $this->actingAs($this->admin)->put(route('admin.spk.update', $this->workOrder), [
            'title' => 'Pasang ONT baru',
            'description' => null,
            'customer_id' => $this->customer->public_id,
            'subscription_id' => $this->subscription->public_id,
            'location_id' => $location->public_id,
            'priority' => 'high',
            'scheduled_date' => $visit,
            OptimisticLock::FIELD => OptimisticLock::version($this->workOrder->fresh()),
        ])->assertRedirect(route('admin.spk.index'))->assertSessionHas('success', 'SPK berhasil diubah.');

        $saved = $this->workOrder->fresh();
        $this->assertSame(['Pasang ONT baru', 'high', $visit, $location->id], [$saved->title, $saved->priority, $saved->scheduled_date->toDateString(), $saved->location_id]);
    }

    private function spk(string $status, string $type = 'installation'): WorkOrder
    {
        return WorkOrderFactory::new()->create([
            'company_id' => $this->companyId,
            'type' => $type,
            'status' => $status,
            'customer_id' => $this->customer->id,
            'subscription_id' => $this->subscription->id,
            'assigned_to' => null,
            'created_by' => $this->admin->id,
        ]);
    }
}
