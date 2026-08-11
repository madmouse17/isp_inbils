<?php

namespace Tests\Feature\Ticketing;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Modules\Ticketing\Jobs\CheckTicketSlaBreachesJob;
use Modules\Ticketing\Models\Ticket;
use Modules\Ticketing\Models\TicketCategory;
use Modules\Ticketing\Notifications\TicketSlaBreachedNotification;
use Tests\TestCase;
use Tests\Traits\CreatesCompanyUser;

class TicketSlaBreachTest extends TestCase
{
    use CreatesCompanyUser;
    use RefreshDatabase;

    public function test_sla_breach_job_notifies_once_and_marks_notified(): void
    {
        Notification::fake();

        $admin = $this->createCompanyUser();
        $category = TicketCategory::create([
            'company_id' => $admin->company_id,
            'name' => 'Support',
            'code' => 'SUP',
            'default_sla_hours' => 1,
            'default_priority' => 'high',
            'is_active' => true,
        ]);

        $ticket = Ticket::create([
            'company_id' => $admin->company_id,
            'code' => 'TKT-2026-00099',
            'title' => 'SLA breach candidate',
            'description' => 'late',
            'source' => 'customer',
            'category_id' => $category->id,
            'status' => 'open',
            'priority' => 'high',
            'sla_deadline' => now()->subHour(),
            'created_by' => $admin->id,
        ]);

        (new CheckTicketSlaBreachesJob())->handle();

        Notification::assertSentTo($admin, TicketSlaBreachedNotification::class);
        $this->assertTrue(Cache::has("ticket:sla-breach:{$ticket->id}:resolution"));

        Notification::fake();
        (new CheckTicketSlaBreachesJob())->handle();
        Notification::assertNothingSent();
    }

    public function test_sla_breach_job_with_company_processes_only_that_tenant(): void
    {
        Notification::fake();

        $adminA = $this->createCompanyUser();
        $adminB = $this->createCompanyUser();
        $categoryA = TicketCategory::create([
            'company_id' => $adminA->company_id,
            'name' => 'Support A',
            'code' => 'SLA-A',
            'default_sla_hours' => 1,
            'default_priority' => 'high',
            'is_active' => true,
        ]);
        $categoryB = TicketCategory::create([
            'company_id' => $adminB->company_id,
            'name' => 'Support B',
            'code' => 'SLA-B',
            'default_sla_hours' => 1,
            'default_priority' => 'high',
            'is_active' => true,
        ]);
        Ticket::withoutCompany()->create([
            'company_id' => $adminA->company_id,
            'code' => 'TKT-2026-SLAA',
            'title' => 'SLA A',
            'description' => 'late',
            'source' => 'customer',
            'category_id' => $categoryA->id,
            'status' => 'open',
            'priority' => 'high',
            'sla_deadline' => now()->subHour(),
            'created_by' => $adminA->id,
        ]);
        Ticket::withoutCompany()->create([
            'company_id' => $adminB->company_id,
            'code' => 'TKT-2026-SLAB',
            'title' => 'SLA B',
            'description' => 'late',
            'source' => 'customer',
            'category_id' => $categoryB->id,
            'status' => 'open',
            'priority' => 'high',
            'sla_deadline' => now()->subHour(),
            'created_by' => $adminB->id,
        ]);

        (new CheckTicketSlaBreachesJob($adminA->company_id))->handle();

        Notification::assertSentTo($adminA, TicketSlaBreachedNotification::class);
        Notification::assertNotSentTo($adminB, TicketSlaBreachedNotification::class);
    }

    public function test_sla_command_iterates_active_companies_and_is_registered(): void
    {
        Notification::fake();

        $adminA = $this->createCompanyUser();
        $adminB = $this->createCompanyUser();
        $adminB->company->update(['is_active' => false]);
        $categoryA = TicketCategory::create([
            'company_id' => $adminA->company_id,
            'name' => 'Support Active',
            'code' => 'SLA-ACT',
            'default_sla_hours' => 1,
            'default_priority' => 'high',
            'is_active' => true,
        ]);
        $categoryB = TicketCategory::create([
            'company_id' => $adminB->company_id,
            'name' => 'Support Inactive',
            'code' => 'SLA-INA',
            'default_sla_hours' => 1,
            'default_priority' => 'high',
            'is_active' => true,
        ]);
        Ticket::withoutCompany()->create([
            'company_id' => $adminA->company_id,
            'code' => 'TKT-2026-ACTIVE',
            'title' => 'Active company SLA',
            'description' => 'late',
            'source' => 'customer',
            'category_id' => $categoryA->id,
            'status' => 'open',
            'priority' => 'high',
            'sla_deadline' => now()->subHour(),
            'created_by' => $adminA->id,
        ]);
        Ticket::withoutCompany()->create([
            'company_id' => $adminB->company_id,
            'code' => 'TKT-2026-INACTIVE',
            'title' => 'Inactive company SLA',
            'description' => 'late',
            'source' => 'customer',
            'category_id' => $categoryB->id,
            'status' => 'open',
            'priority' => 'high',
            'sla_deadline' => now()->subHour(),
            'created_by' => $adminB->id,
        ]);

        $this->artisan('list', ['--raw' => true])->expectsOutputToContain('tickets:sla')->assertExitCode(0);
        $this->artisan('help', ['command_name' => 'tickets:sla'])->assertExitCode(0);
        $this->artisan('tickets:sla')->assertExitCode(0);

        Notification::assertSentTo($adminA, TicketSlaBreachedNotification::class);
        Notification::assertNotSentTo($adminB, TicketSlaBreachedNotification::class);
    }

    public function test_sla_breach_skips_resolved_tickets(): void
    {
        Notification::fake();

        $admin = $this->createCompanyUser();
        $category = TicketCategory::create([
            'company_id' => $admin->company_id,
            'name' => 'Support',
            'code' => 'SUP2',
            'default_sla_hours' => 1,
            'default_priority' => 'high',
            'is_active' => true,
        ]);

        Ticket::create([
            'company_id' => $admin->company_id,
            'code' => 'TKT-2026-00100',
            'title' => 'Already resolved',
            'description' => 'done',
            'source' => 'customer',
            'category_id' => $category->id,
            'status' => 'resolved',
            'priority' => 'high',
            'sla_deadline' => now()->subHour(),
            'resolved_at' => now(),
            'created_by' => $admin->id,
        ]);

        (new CheckTicketSlaBreachesJob())->handle();

        Notification::assertNothingSent();
    }

    public function test_job_has_retry_bounds(): void
    {
        $job = new CheckTicketSlaBreachesJob();
        $this->assertGreaterThan(0, $job->tries);
        $this->assertNotNull($job->timeout);
        $this->assertNotEmpty($job->backoff ?? $job->backoff());
    }
}
