<?php

declare(strict_types=1);

namespace Tests\Feature\Ticketing;

use App\Models\Core\Company;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Modules\Ticketing\Jobs\CheckTicketSlaBreachesJob;
use Modules\Ticketing\Models\Ticket;
use Modules\Ticketing\Models\TicketCategory;
use Tests\TestCase;

class SlaMultiCompanyTest extends TestCase
{
    use RefreshDatabase;

    public function test_sla_job_processes_all_active_companies(): void
    {
        Notification::fake();
        [$companyA, $companyB, $userA, $userB] = $this->companiesAndUsers();
        $ticketA = $this->breachedTicket($companyA->id, $userA->id);
        $ticketB = $this->breachedTicket($companyB->id, $userB->id);

        (new CheckTicketSlaBreachesJob())->handle();

        $this->assertNotNull($ticketA->fresh()->sla_breached_notified_at);
        $this->assertNotNull($ticketB->fresh()->sla_breached_notified_at);
    }

    public function test_sla_job_with_specific_company_only_processes_that_company(): void
    {
        Notification::fake();
        [$companyA, $companyB, $userA, $userB] = $this->companiesAndUsers();
        $ticketA = $this->breachedTicket($companyA->id, $userA->id);
        $ticketB = $this->breachedTicket($companyB->id, $userB->id);

        (new CheckTicketSlaBreachesJob($companyA->id))->handle();

        $this->assertNotNull($ticketA->fresh()->sla_breached_notified_at);
        $this->assertNull($ticketB->fresh()->sla_breached_notified_at);
    }

    public function test_sla_job_does_not_process_tickets_from_inactive_companies(): void
    {
        Notification::fake();
        [$companyA, $companyB, $userA, $userB] = $this->companiesAndUsers();
        $companyB->update(['is_active' => false]);
        $ticketA = $this->breachedTicket($companyA->id, $userA->id);
        $ticketB = $this->breachedTicket($companyB->id, $userB->id);

        (new CheckTicketSlaBreachesJob())->handle();

        $this->assertNotNull($ticketA->fresh()->sla_breached_notified_at);
        $this->assertNull($ticketB->fresh()->sla_breached_notified_at);
    }

    public function test_sla_job_preserves_tenant_isolation(): void
    {
        Notification::fake();
        [$companyA, $companyB, $userA, $userB] = $this->companiesAndUsers();
        $ticketA = $this->breachedTicket($companyA->id, $userA->id);
        $ticketB = $this->breachedTicket($companyB->id, $userB->id);

        (new CheckTicketSlaBreachesJob($companyA->id))->handle();

        $this->assertNotNull($ticketA->fresh()->sla_breached_notified_at);
        $this->assertNull($ticketB->fresh()->sla_breached_notified_at);
        $this->assertSame($companyA->id, $ticketA->fresh()->company_id);
    }

    private function companiesAndUsers(): array
    {
        $companyA = Company::factory()->create(['is_active' => true]);
        $companyB = Company::factory()->create(['is_active' => true]);
        $userA = User::factory()->create(['company_id' => $companyA->id]);
        $userB = User::factory()->create(['company_id' => $companyB->id]);

        return [$companyA, $companyB, $userA, $userB];
    }

    private function breachedTicket(int $companyId, int $userId): Ticket
    {
        Auth::login(User::findOrFail($userId));

        $category = TicketCategory::query()->create([
            'company_id' => $companyId,
            'name' => 'SLA',
            'code' => 'SLA-'.fake()->unique()->numberBetween(1, 99999),
            'default_sla_hours' => 2,
            'default_priority' => 'medium',
            'is_active' => true,
        ]);

        return Ticket::factory()->create([
            'company_id' => $companyId,
            'category_id' => $category->id,
            'sla_deadline' => Carbon::now()->subHour(),
            'status' => 'open',
        ]);
    }
}
