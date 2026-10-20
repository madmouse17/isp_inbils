<?php

declare(strict_types=1);

namespace Tests\Feature\Ticketing;

use App\Models\Core\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Modules\Ticketing\Models\Ticket;
use Modules\Ticketing\Models\TicketCategory;
use Modules\Ticketing\Services\TicketService;
use Tests\TestCase;
use Tests\Traits\CreatesCompanyUser;
use Tests\Traits\InteractsWithMobileApi;

/**
 * K9: the first response is the first public reply of staff; Mulai counts only when no reply came first; an internal note
 * and a customer's comment never count. The ticket says which of the two it was.
 */
class TicketFirstResponseSourceTest extends TestCase
{
    use CreatesCompanyUser;
    use InteractsWithMobileApi;
    use RefreshDatabase;

    private const BACKFILL = 'Modules/Ticketing/database/migrations/2026_10_28_100200_backfill_first_response_source_on_tickets.php';

    private User $admin;

    private User $handler;

    private TicketCategory $category;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->travelTo('2026-10-28 03:00:00');
        $this->admin = $this->createCompanyUser();
        $this->handler = User::factory()->create(['company_id' => $this->admin->company_id, 'is_active' => true]);
        $this->handler->givePermissionTo('ticket.start');
        $this->category = TicketCategory::query()->create([
            'company_id' => $this->admin->company_id, 'name' => 'Support', 'code' => 'SUP-'.Str::random(6),
            'default_sla_hours' => 24, 'default_priority' => 'medium', 'is_active' => true,
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function ticket(array $overrides = []): Ticket
    {
        return Ticket::query()->create($overrides + [
            'company_id' => $this->admin->company_id, 'code' => 'TKT-'.Str::random(10), 'title' => 'Internet lambat',
            'description' => 'Sering putus', 'source' => 'internal', 'category_id' => $this->category->id, 'status' => 'assigned',
            'priority' => 'medium', 'assigned_to' => $this->handler->id, 'created_by' => $this->admin->id,
            'sla_deadline' => now()->addDay(),
        ]);
    }

    public function test_first_public_staff_comment_sets_reply_source(): void
    {
        $this->actingAs($this->admin);
        $ticket = $this->ticket();

        TicketService::addComment($ticket, ['body' => 'Kami cek jaringannya', 'is_internal' => false], $this->admin->id);

        $answered = $ticket->fresh();
        $this->assertSame('reply', $answered->first_response_source);
        $this->assertEquals(now(), $answered->first_response_at);

        $this->travel(10)->minutes();
        TicketService::addComment($ticket, ['body' => 'Teknisi segera datang', 'is_internal' => false], $this->admin->id);

        $this->assertEquals(now()->subMinutes(10), $ticket->fresh()->first_response_at, 'only the first reply counts');
        $this->assertSame('reply', $ticket->fresh()->first_response_source);
    }

    public function test_internal_note_does_not_count(): void
    {
        $this->actingAs($this->admin);
        $ticket = $this->ticket();

        TicketService::addComment($ticket, ['body' => 'Cek ke OLT dulu', 'is_internal' => true], $this->admin->id);

        $this->assertNull($ticket->fresh()->first_response_at);
        $this->assertNull($ticket->fresh()->first_response_source);
    }

    public function test_customer_comment_does_not_count(): void
    {
        [$customerUser, $customer] = $this->customerLogin(Company::query()->findOrFail($this->admin->company_id));
        $ticket = $this->ticket(['source' => 'customer', 'customer_id' => $customer->id, 'created_by' => $customerUser->id]);

        $this->api('POST', "customer/tickets/{$ticket->public_id}/comments", ['body' => 'Kapan datang?'], $this->issueToken($customerUser, 'customer'))->assertCreated();

        $this->assertNull($ticket->fresh()->first_response_at);
        $this->assertNull($ticket->fresh()->first_response_source);
    }

    public function test_start_sets_start_source_only_when_empty(): void
    {
        $fresh = $this->ticket();
        $this->actingAs($this->handler);

        TicketService::startWork($fresh);

        $this->assertSame('start', $fresh->fresh()->first_response_source);
        $this->assertEquals(now(), $fresh->fresh()->first_response_at);

        // A reply came first: Mulai keeps it as the first response.
        $answered = $this->ticket();
        $this->actingAs($this->admin);
        TicketService::addComment($answered, ['body' => 'Sedang kami tangani', 'is_internal' => false], $this->admin->id);
        $repliedAt = $answered->fresh()->first_response_at;
        $this->travel(20)->minutes();

        $this->actingAs($this->handler);
        TicketService::startWork($answered->fresh());

        $started = $answered->fresh();
        $this->assertSame('on_progress', $started->status);
        $this->assertSame('reply', $started->first_response_source);
        $this->assertEquals($repliedAt, $started->first_response_at);
    }

    public function test_backfill_marks_existing_responses_as_start(): void
    {
        $answered = $this->ticket(['first_response_at' => now()->subDay()]);
        $unanswered = $this->ticket();
        $replied = $this->ticket(['first_response_at' => now()->subHour(), 'first_response_source' => 'reply']);
        $versions = Ticket::withoutCompany()->whereKey([$answered->id, $unanswered->id, $replied->id])->pluck('updated_at', 'id');

        $migration = require base_path(self::BACKFILL);
        $migration->up();

        $this->assertSame('start', $answered->fresh()->first_response_source);
        $this->assertNull($unanswered->fresh()->first_response_source);
        $this->assertSame('reply', $replied->fresh()->first_response_source, 'a source already written stays');
        $this->assertEquals($versions, Ticket::withoutCompany()->whereKey([$answered->id, $unanswered->id, $replied->id])->pluck('updated_at', 'id'), 'the version a form carries is not touched');

        $migration->down();

        $this->assertNull($answered->fresh()->first_response_source);
        $this->assertSame(1, DB::table('tickets')->where('first_response_source', 'reply')->count());
    }
}
