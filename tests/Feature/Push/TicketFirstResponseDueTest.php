<?php

namespace Tests\Feature\Push;

use App\Models\Core\Company;
use App\Models\Core\DeviceToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Modules\Ticketing\Jobs\CheckTicketSlaBreachesJob;
use Modules\Ticketing\Models\Ticket;
use Modules\Ticketing\Models\TicketCategory;
use Modules\Ticketing\Notifications\TicketFirstResponseDueNotification;
use Tests\TestCase;
use Tests\Traits\CreatesCompanyUser;
use Tests\Traits\FakesFirebaseMessaging;
use Tests\Traits\InteractsWithMobileApi;

/**
 * The technician a ticket was assigned to hears, once, that its first-response target is 30 minutes away or already missed
 * and nobody has started the ticket. Found by the same scheduled job as the SLA breach (tickets:sla, every 15 minutes).
 */
class TicketFirstResponseDueTest extends TestCase
{
    use CreatesCompanyUser;
    use FakesFirebaseMessaging;
    use InteractsWithMobileApi;
    use RefreshDatabase;

    private User $admin;

    private User $technician;

    private TicketCategory $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeFirebase();
        $this->travelTo('2026-10-21 09:00:00');
        $this->admin = $this->createCompanyUser();
        $this->technician = $this->technicianLogin(Company::query()->findOrFail($this->admin->company_id));
        $this->category = TicketCategory::create([
            'company_id' => $this->admin->company_id,
            'name' => 'Gangguan',
            'code' => 'GNG',
            'default_sla_hours' => 8,
            'default_priority' => 'high',
            'is_active' => true,
        ]);
        DeviceToken::factory()->create(['user_id' => $this->technician->id, 'app' => 'technician', 'token' => 'technician-install']);
    }

    /** @param array<string, mixed> $overrides */
    private function ticket(array $overrides = []): Ticket
    {
        return Ticket::withoutCompany()->create($overrides + [
            'company_id' => $this->admin->company_id,
            'code' => 'TKT-2026-00042',
            'title' => 'Internet mati',
            'description' => 'Tidak ada sinyal sejak pagi',
            'source' => 'customer',
            'category_id' => $this->category->id,
            'status' => 'assigned',
            'priority' => 'high',
            'assigned_to' => $this->technician->id,
            'created_by' => $this->admin->id,
            'sla_deadline' => now()->addHours(8),
            'response_deadline' => now()->addMinutes(20),
        ]);
    }

    private function runJob(?int $companyId = null): void
    {
        (new CheckTicketSlaBreachesJob($companyId))->handle();
    }

    public function test_a_ticket_20_minutes_from_its_response_target_tells_the_assignee_once_in_the_bell_and_on_the_phone(): void
    {
        $ticket = $this->ticket();

        $this->runJob();

        $bell = $this->technician->notifications()->sole();
        $this->assertEqualsCanonicalizing([
            'type' => 'ticket_first_response_due',
            'ticket_id' => $ticket->id,
            'ticket_code' => 'TKT-2026-00042',
            'state' => 'due_soon',
            'company_id' => $ticket->company_id,
        ], $bell->data);
        $this->assertSame([
            'type' => 'ticket_first_response_due',
            'subject_type' => 'ticket',
            'subject_id' => (string) $ticket->id,
            'notification_id' => $bell->id,
            'title' => 'Respons pertama segera jatuh tempo',
            'body' => 'Tiket TKT-2026-00042 perlu direspons dalam 30 menit.',
        ], $this->onlyPush()['data']);
        $this->assertSame(['technician-install'], $this->pushedTokens());
        $this->assertNotNull($ticket->fresh()->response_due_notified_at);

        $this->runJob();
        $this->travel(10)->minutes();
        $this->runJob();
        $this->assertSame(1, $this->technician->notifications()->count(), 'once per ticket, however often the job runs');
        $this->assertCount(1, $this->multicasts);
    }

    public function test_a_ticket_that_already_missed_its_response_target_says_so(): void
    {
        $this->ticket(['response_deadline' => now()->subMinutes(5)]);

        $this->runJob();

        $this->assertSame('breached', $this->technician->notifications()->sole()->data['state']);
        $push = $this->onlyPush()['data'];
        $this->assertSame('Batas respons pertama terlewat', $push['title']);
        $this->assertSame('Tiket TKT-2026-00042 melewati batas respons pertama.', $push['body']);
    }

    public function test_the_window_opens_30_minutes_before_the_target(): void
    {
        $this->ticket(['response_deadline' => now()->addMinutes(45)]);

        $this->runJob();
        $this->assertSame(0, $this->technician->notifications()->count(), '45 minutes out: too early');

        $this->travel(16)->minutes();
        $this->runJob();
        $this->assertSame(1, $this->technician->notifications()->count(), '29 minutes out: inside the window');
    }

    public function test_only_an_assigned_unanswered_ticket_with_a_target_counts(): void
    {
        $this->ticket(['code' => 'TKT-ANSWERED', 'first_response_at' => now()->subMinutes(5)]);
        $this->ticket(['code' => 'TKT-STARTED', 'status' => 'on_progress']);
        $this->ticket(['code' => 'TKT-RESOLVED', 'status' => 'resolved']);
        $this->ticket(['code' => 'TKT-UNASSIGNED', 'status' => 'open', 'assigned_to' => null]);
        $this->ticket(['code' => 'TKT-NO-TARGET', 'response_deadline' => null]);
        $this->technician->forceFill(['is_active' => false])->save();
        $this->ticket(['code' => 'TKT-INACTIVE-HANDLER']);

        $this->runJob();

        $this->assertSame(0, $this->technician->notifications()->count());
        $this->assertSame([], $this->multicasts);
        $this->assertNull(Ticket::withoutCompany()->where('code', 'TKT-INACTIVE-HANDLER')->value('response_due_notified_at'));
    }

    public function test_a_job_for_one_company_leaves_the_tickets_of_another_alone(): void
    {
        $other = $this->createCompanyUser();
        $this->ticket();
        Notification::fake();

        $this->runJob((int) $other->company_id);

        Notification::assertNothingSent();
        $this->runJob((int) $this->admin->company_id);
        Notification::assertSentTo($this->technician, TicketFirstResponseDueNotification::class);
    }

    public function test_a_handler_without_the_app_still_gets_the_bell_row(): void
    {
        DeviceToken::query()->delete();
        $this->ticket();

        $this->runJob();

        $this->assertSame(1, $this->technician->notifications()->count());
        $this->assertSame([], $this->multicasts);
    }
}
