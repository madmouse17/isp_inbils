<?php

namespace Tests\Feature\Api\V1\Technician;

use App\Models\Core\Company;
use App\Models\Core\Customer;
use App\Models\Core\EmployeeProfile;
use App\Services\Core\CompanyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\SPK\Database\Factories\WorkOrderFactory;
use Modules\SPK\Models\WorkOrder;
use Modules\Ticketing\Models\Ticket;
use Modules\Ticketing\Models\TicketCategory;
use Tests\TestCase;
use Tests\Traits\InteractsWithMobileApi;

class MyWorkTest extends TestCase
{
    use InteractsWithMobileApi;
    use RefreshDatabase;

    public function test_my_work_matches_the_web_items_and_summary_for_same_filters(): void
    {
        $technician = $this->technicianLogin();
        CompanyService::useCompanyForTests((int) $technician->company_id);
        $otherTechnician = $this->technicianLogin(Company::query()->findOrFail($technician->company_id));
        $ticket = $this->ticketFor($technician->id, 'assigned', 'high', now()->subDays(2));
        $this->workOrderFor($technician->id, 'assigned', 'medium', now()->subDay());
        $this->ticketFor($otherTechnician->id, 'assigned', 'high', now());
        $query = ['domain' => 'ticket', 'status' => 'assigned', 'sort' => 'created_at', 'direction' => 'desc', 'per_page' => 10];

        $web = $this->actingAs($technician)->get(route('admin.my-work.index', $query))->assertOk();
        $api = $this->api('GET', 'technician/my-work?'.http_build_query($query), token: $this->issueToken($technician, 'technician'))
            ->assertOk()
            ->assertJsonPath('meta.summary', $web->inertiaProps('summary'));

        $this->assertSame(
            collect($web->inertiaProps('items.data'))->pluck('composite_id')->all(),
            collect($api->json('data'))->pluck('composite_id')->all(),
        );
        $this->assertSame('ticket:'.$ticket->id, $api->json('data.0.composite_id'));
    }

    public function test_filters_are_passed_through_to_the_existing_query(): void
    {
        $technician = $this->technicianLogin();
        CompanyService::useCompanyForTests((int) $technician->company_id);
        $ticket = $this->ticketFor($technician->id, 'assigned', 'urgent', now()->subMinutes(5), 'Router down');
        $this->workOrderFor($technician->id, 'assigned', 'low', now()->subMinutes(10), 'Fiber install');

        $this->api('GET', 'technician/my-work?search=Router&priority=urgent&per_page=10', token: $this->issueToken($technician, 'technician'))
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.domain', 'ticket')
            ->assertJsonPath('data.0.id', $ticket->id);
    }

    public function test_customer_token_and_ineligible_technician_are_rejected(): void
    {
        [$customerUser] = $this->customerLogin();
        $technician = $this->technicianLogin();
        EmployeeProfile::withoutCompany()->where('user_id', $technician->id)->update(['status' => 'inactive']);

        $this->api('GET', 'technician/my-work', token: $this->issueToken($customerUser, 'customer'))
            ->assertForbidden()
            ->assertJsonPath('code', 'forbidden');
        $this->api('GET', 'technician/my-work', token: $technician->createToken('Test phone', ['technician'], now()->addDays(30))->plainTextToken)
            ->assertForbidden()
            ->assertJsonPath('code', 'app_not_allowed');
    }

    /** @param \DateTimeInterface $createdAt */
    private function ticketFor(int $assigneeId, string $status, string $priority, $createdAt, string $title = 'Internet trouble'): Ticket
    {
        $companyId = (int) CompanyService::currentId();
        $category = TicketCategory::query()->create([
            'company_id' => $companyId,
            'name' => 'General Support',
            'code' => 'SUP-'.uniqid(),
            'default_sla_hours' => 24,
            'default_priority' => 'medium',
            'is_active' => true,
        ]);

        return Ticket::query()->create([
            'company_id' => $companyId,
            'code' => 'TKT-'.fake()->unique()->numberBetween(10000, 99999),
            'title' => $title,
            'description' => 'Cannot connect',
            'source' => 'internal',
            'category_id' => $category->id,
            'customer_id' => Customer::factory()->create(['company_id' => $companyId])->id,
            'status' => $status,
            'priority' => $priority,
            'assigned_to' => $assigneeId,
            'created_by' => $assigneeId,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }

    /** @param \DateTimeInterface $createdAt */
    private function workOrderFor(int $assigneeId, string $status, string $priority, $createdAt, string $title = 'SPK job'): WorkOrder
    {
        $companyId = (int) CompanyService::currentId();

        return WorkOrderFactory::new()->create([
            'company_id' => $companyId,
            'assigned_to' => $assigneeId,
            'status' => $status,
            'priority' => $priority,
            'title' => $title,
            'created_by' => $assigneeId,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }
}
