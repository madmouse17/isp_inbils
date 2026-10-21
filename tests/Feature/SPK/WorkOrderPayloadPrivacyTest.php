<?php

declare(strict_types=1);

namespace Tests\Feature\SPK;

use App\Models\Core\Customer;
use App\Models\Core\EmployeeProfile;
use App\Models\Core\ServiceSubscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Modules\SPK\Database\Factories\WorkOrderFactory;
use Modules\SPK\Models\WorkOrder;
use Modules\SPK\Services\SpkService;
use Tests\TestCase;
use Tests\Traits\AsksInertiaForProps;
use Tests\Traits\CreatesCompanyUser;

/** The SPK pages name the customer, the subscription and the technician; the customer's record stays on the customer pages. */
class WorkOrderPayloadPrivacyTest extends TestCase
{
    use AsksInertiaForProps;
    use CreatesCompanyUser;
    use RefreshDatabase;

    private const PRIVATE = ['sari.pribadi@example.test', '99.888.777.6-543.210', 'Pelanggan lama, minta dihubungi sore hari.', 'Bapak Rahmat', '081234567890', 'teknisi.pribadi@example.test'];

    private User $admin;

    private WorkOrder $workOrder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->admin = $this->createCompanyUser();
        $companyId = (int) $this->admin->company_id;
        $customer = Customer::factory()->create([
            'company_id' => $companyId, 'name' => 'Sari Wulandari', 'phone' => '081234567890', 'email' => self::PRIVATE[0],
            'tax_id' => self::PRIVATE[1], 'notes' => self::PRIVATE[2], 'contact_person' => self::PRIVATE[3],
        ]);
        $subscription = ServiceSubscription::factory()->create(['company_id' => $companyId, 'customer_id' => $customer->id, 'notes' => 'Catatan langganan rahasia']);
        $technician = User::factory()->create(['company_id' => $companyId, 'name' => 'Budi Teknisi', 'email' => self::PRIVATE[5], 'is_active' => true]);
        $this->workOrder = WorkOrderFactory::new()->create([
            'company_id' => $companyId, 'customer_id' => $customer->id, 'subscription_id' => $subscription->id,
            'assigned_to' => $technician->id, 'created_by' => $this->admin->id,
        ]);
    }

    public function test_index_and_show_embed_customer_summary_only(): void
    {
        $customer = Customer::query()->where('name', 'Sari Wulandari')->sole();
        $subscription = ServiceSubscription::query()->where('customer_id', Customer::query()->where('name', 'Sari Wulandari')->value('id'))->sole();

        foreach ([route('admin.spk.index'), route('admin.spk.show', $this->workOrder)] as $url) {
            $props = $this->actingAs($this->admin)->get($url)->assertOk()->viewData('page')['props'];
            $row = $props['workOrders']['data'][0] ?? $props['workOrder']['data'];

            $this->assertSame(['id' => $customer->public_id, 'code' => $customer->code, 'name' => 'Sari Wulandari'], $row['customer']);
            $this->assertSame(['id' => $subscription->public_id, 'code' => $subscription->code], $row['subscription']);

            $json = (string) json_encode($props['workOrders'] ?? $props['workOrder']);
            foreach (['Catatan langganan rahasia', ...self::PRIVATE] as $private) {
                $this->assertStringNotContainsString($private, $json, $private);
            }
        }
    }

    public function test_assignee_is_an_id_and_a_name(): void
    {
        $assignee = User::query()->where('name', 'Budi Teknisi')->sole();
        $props = $this->actingAs($this->admin)->get(route('admin.spk.show', $this->workOrder))->assertOk()->viewData('page')['props'];

        $this->assertSame(['id' => $assignee->public_id, 'name' => 'Budi Teknisi'], $props['workOrder']['data']['assignee']);
    }

    public function test_evidence_only_on_show(): void
    {
        $this->workOrder->addMediaFromString('test')
            ->usingFileName('test.jpg')
            ->withCustomProperties(['company_id' => $this->workOrder->company_id, 'type' => 'photo', 'uploaded_by' => $this->admin->id])
            ->toMediaCollection('evidence', 'public');

        $show = $this->actingAs($this->admin)->get(route('admin.spk.show', $this->workOrder))->assertOk()->viewData('page')['props'];
        $index = $this->actingAs($this->admin)->get(route('admin.spk.index'))->assertOk()->viewData('page')['props'];
        $edit = $this->actingAs($this->admin)->get(route('admin.spk.edit', $this->workOrder))->assertOk()->viewData('page')['props'];

        $this->assertCount(1, $show['workOrder']['data']['evidence']);
        $this->assertArrayNotHasKey('evidence', $index['workOrders']['data'][0]);
        $this->assertArrayNotHasKey('evidence', $edit['workOrder']['data']);
    }

    public function test_the_assignment_history_names_each_technician_by_id_and_name_only(): void
    {
        Notification::fake();
        $companyId = (int) $this->admin->company_id;
        $technician = User::factory()->create(['company_id' => $companyId, 'name' => 'Rina Teknisi', 'email' => 'rina.teknisi@example.test', 'is_active' => true]);
        $this->grantRole($technician, 'technician');
        EmployeeProfile::factory()->create(['company_id' => $companyId, 'user_id' => $technician->id, 'status' => 'active']);
        $workOrder = WorkOrderFactory::new()->create(['company_id' => $companyId, 'type' => 'maintenance', 'status' => 'generated', 'assigned_to' => null, 'created_by' => $this->admin->id]);
        $this->actingAs($this->admin);
        // The factory makes no assignment rows: the history the page lists exists only after a real assignment.
        SpkService::assign($workOrder, $technician->id, $this->admin->id, null, $workOrder->fresh()->updated_at->utc()->format('Y-m-d\TH:i:s.v\Z'));
        // Someone who may read SPK and nothing about people (the warehouse template) gets the same page.
        $reader = User::factory()->create(['company_id' => $companyId, 'email_verified_at' => now(), 'is_active' => true]);
        $this->grantPermissions($reader, ['app.web', 'spk.view']);

        foreach ([$this->admin, $reader] as $viewer) {
            $props = $this->actingAs($viewer)->get(route('admin.spk.show', $workOrder))->assertOk()->viewData('page')['props'];
            $assignments = $props['workOrder']['data']['assignments'];

            $this->assertCount(1, $assignments);
            $this->assertSame($technician->public_id, $assignments[0]['technician_id']);
            $this->assertSame(['id' => $technician->public_id, 'name' => 'Rina Teknisi'], $assignments[0]['technician'], 'an id and a name, nothing else of the user');
            $this->assertStringNotContainsString('rina.teknisi@example.test', (string) json_encode($props));
        }
    }

    public function test_the_edit_page_sends_no_customer_record_not_even_through_its_pickers(): void
    {
        $props = $this->actingAs($this->admin)->get(route('admin.spk.edit', $this->workOrder))->assertOk()->viewData('page')['props'];

        foreach (['Catatan langganan rahasia', ...self::PRIVATE] as $private) {
            $this->assertStringNotContainsString($private, (string) json_encode($props), $private);
        }
        $this->assertArrayNotHasKey('customers', $props);
        $this->assertArrayNotHasKey('subscriptions', $props);

        // What the pickers answer to a search: a value, a label and a description for each, never the record.
        $customers = $this->get(route('admin.spk.edit', [$this->workOrder, 'customer_search' => 'Sari']), $this->partialReload('Admin/SPK/Edit', 'customerOptions'))
            ->assertOk()->json('props.customerOptions');
        $customer = Customer::query()->where('name', 'Sari Wulandari')->sole();
        $subscriptions = $this->get(route('admin.spk.edit', [$this->workOrder, 'customer_id' => $customer->public_id, 'subscription_search' => 'SUB']), $this->partialReload('Admin/SPK/Edit', 'subscriptionOptions'))
            ->assertOk()->json('props.subscriptionOptions');

        $this->assertCount(1, $customers);
        $this->assertCount(1, $subscriptions);
        foreach ([...$customers, ...$subscriptions] as $option) {
            $this->assertSame(['value', 'label', 'description'], array_keys($option));
        }
        foreach (['Catatan langganan rahasia', ...self::PRIVATE] as $private) {
            $this->assertStringNotContainsString($private, (string) json_encode([$customers, $subscriptions]), $private);
        }
    }
}
