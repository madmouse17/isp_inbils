<?php

declare(strict_types=1);

namespace Tests\Feature\Ticketing;

use App\Models\Core\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Modules\Ticketing\Models\Ticket;
use Modules\Ticketing\Models\TicketCategory;
use Modules\Ticketing\Models\TicketComment;
use Modules\Ticketing\Notifications\TicketStaffNotification;
use Modules\Ticketing\Services\TicketService;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Feature\Api\V1\PostsMultipart;
use Tests\TestCase;
use Tests\Traits\InteractsWithMobileApi;

/**
 * K13: a closed ticket takes no reply, a customer reply on a resolved ticket brings it back to its handler and tells the
 * handler and dispatch, and staff comments on a closed ticket are refused too.
 */
class TicketReplyRulesTest extends TestCase
{
    use InteractsWithMobileApi;
    use PostsMultipart;
    use RefreshDatabase;

    private const CLOSED_MESSAGE = 'Tiket ditutup. Masih bermasalah? Buat tiket baru.';

    private User $customerUser;

    private Customer $customer;

    private string $token;

    private User $dispatcher;

    private User $handler;

    private TicketCategory $category;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        [$this->customerUser, $this->customer] = $this->customerLogin();
        $this->token = $this->issueToken($this->customerUser, 'customer');
        $this->dispatcher = $this->staffHolding('ticket.assign');
        $this->handler = $this->staffHolding('ticket.start');
        $this->category = TicketCategory::query()->create([
            'company_id' => $this->customerUser->company_id, 'name' => 'Support', 'code' => 'SUP-'.Str::random(6),
            'default_sla_hours' => 24, 'default_priority' => 'medium', 'is_active' => true,
        ]);
    }

    private function staffHolding(string $permission): User
    {
        $user = User::factory()->create(['company_id' => $this->customerUser->company_id, 'is_active' => true]);
        $user->givePermissionTo($permission);

        return $user;
    }

    /** @param array<string, mixed> $overrides */
    private function ticket(array $overrides = []): Ticket
    {
        return Ticket::query()->create($overrides + [
            'company_id' => $this->customerUser->company_id, 'code' => 'TKT-'.Str::random(10), 'title' => 'Internet mati',
            'description' => 'Lampu LOS merah', 'source' => 'customer', 'category_id' => $this->category->id, 'status' => 'open',
            'priority' => 'medium', 'customer_id' => $this->customer->id, 'created_by' => $this->customerUser->id,
            'sla_deadline' => now()->addDay(),
        ]);
    }

    private function reply(Ticket $ticket, string $body = 'Masih belum normal'): TestResponse
    {
        return $this->api('POST', "customer/tickets/{$ticket->public_id}/comments", ['body' => $body], $this->token);
    }

    private function commentCount(Ticket $ticket): int
    {
        return TicketComment::withoutCompany()->where('ticket_id', $ticket->id)->count();
    }

    public function test_customer_reply_on_closed_ticket_is_refused_with_ticket_closed(): void
    {
        Notification::fake();
        $ticket = $this->ticket(['status' => 'closed', 'closed_at' => now(), 'assigned_to' => $this->handler->id]);

        $this->reply($ticket)
            ->assertStatus(422)
            ->assertJsonPath('code', 'ticket_closed')
            ->assertJsonPath('message', self::CLOSED_MESSAGE);

        $this->assertSame(0, $this->commentCount($ticket));
        $this->assertSame('closed', $ticket->fresh()->status);
        Notification::assertNothingSent();
    }

    public function test_customer_reply_on_resolved_ticket_reopens_to_assigned_and_notifies(): void
    {
        Notification::fake();
        $ticket = $this->ticket([
            'status' => 'resolved', 'assigned_to' => $this->handler->id, 'first_response_at' => now()->subHours(3),
            'resolved_at' => now()->subHour(), 'resolution_note' => 'Kabel diganti',
        ]);
        $deadline = $ticket->sla_deadline;

        $this->reply($ticket, 'Internet mati lagi')->assertCreated()->assertJsonPath('data.body', 'Internet mati lagi');

        $reopened = $ticket->fresh();
        $this->assertSame('assigned', $reopened->status);
        $this->assertNull($reopened->resolved_at);
        $this->assertNull($reopened->resolution_note);
        $this->assertNotNull($reopened->reopened_at);
        $this->assertEquals($deadline, $reopened->sla_deadline, 'the targets are not restarted');
        $this->assertSame(1, $this->commentCount($ticket));

        $audit = Activity::query()->where('log_name', 'ticket')->where('event', 'reopened')->sole();
        $this->assertSame('resolved', $audit->properties['previous_status']);
        $this->assertSame('Kabel diganti', $audit->properties['resolution_note']);
        $this->assertSame('customer', $audit->properties['by']);

        foreach ([$this->handler, $this->dispatcher] as $watcher) {
            Notification::assertSentTo($watcher, TicketStaffNotification::class, fn (TicketStaffNotification $notice): bool => $notice->stage === 'reopened' && $notice->ticketId === $ticket->id && $notice->companyId === $ticket->company_id);
        }
        Notification::assertSentToTimes($this->dispatcher, TicketStaffNotification::class, 1);
        Notification::assertNotSentTo($this->customerUser, TicketStaffNotification::class);
        Notification::assertNotSentTo($this->customer, TicketStaffNotification::class);
    }

    public function test_customer_reply_without_assignee_reopens_to_open(): void
    {
        Notification::fake();
        $ticket = $this->ticket(['status' => 'resolved', 'resolved_at' => now()->subHour(), 'resolution_note' => 'Sudah normal']);

        $this->reply($ticket)->assertCreated();

        $this->assertSame('open', $ticket->fresh()->status);
        Notification::assertSentTo($this->dispatcher, TicketStaffNotification::class, fn (TicketStaffNotification $notice): bool => $notice->stage === 'reopened');
        Notification::assertNotSentTo($this->handler, TicketStaffNotification::class);
    }

    public function test_customer_reply_on_an_active_ticket_notifies_without_reopening(): void
    {
        Notification::fake();
        $ticket = $this->ticket(['status' => 'on_progress', 'assigned_to' => $this->handler->id]);

        $this->reply($ticket)->assertCreated();

        $this->assertSame('on_progress', $ticket->fresh()->status);
        $this->assertNull($ticket->fresh()->reopened_at);
        foreach ([$this->handler, $this->dispatcher] as $watcher) {
            Notification::assertSentTo($watcher, TicketStaffNotification::class, fn (TicketStaffNotification $notice): bool => $notice->stage === 'customer_replied');
        }
    }

    public function test_staff_comment_on_closed_ticket_is_refused(): void
    {
        $staff = $this->staffHolding('ticket.comment.create');
        $this->actingAs($staff);
        $ticket = $this->ticket(['status' => 'closed', 'closed_at' => now(), 'customer_id' => null]);

        try {
            TicketService::addComment($ticket, ['body' => 'Catatan terlambat', 'is_internal' => true], $staff->id);
            $this->fail('a closed ticket must refuse a staff comment');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertSame('Tiket sudah ditutup.', $e->getMessage());
        }

        $this->assertSame(0, $this->commentCount($ticket));
    }

    public function test_customer_attachment_on_closed_ticket_is_refused(): void
    {
        $ticket = $this->ticket(['status' => 'closed', 'closed_at' => now()]);

        $this->multipart("customer/tickets/{$ticket->public_id}/attachments", [
            'file' => UploadedFile::fake()->image('modem.jpg'),
        ], $this->token)
            ->assertStatus(422)
            ->assertJsonPath('code', 'ticket_closed')
            ->assertJsonPath('message', self::CLOSED_MESSAGE);

        $this->assertCount(0, $ticket->fresh()->getMedia('attachments'));
    }

    public function test_customer_attachment_on_an_open_ticket_still_works(): void
    {
        $ticket = $this->ticket(['status' => 'assigned', 'assigned_to' => $this->handler->id]);

        $this->multipart("customer/tickets/{$ticket->public_id}/attachments", [
            'file' => UploadedFile::fake()->image('modem.jpg'),
        ], $this->token)->assertCreated();

        $this->assertCount(1, $ticket->fresh()->getMedia('attachments'));
    }

    public function test_customer_ticket_json_says_whether_a_reply_is_possible(): void
    {
        $open = $this->ticket(['status' => 'assigned', 'assigned_to' => $this->handler->id]);
        $closed = $this->ticket(['status' => 'closed', 'closed_at' => now()]);

        $this->api('GET', "customer/tickets/{$open->public_id}", token: $this->token)
            ->assertOk()
            ->assertJsonPath('data.can_reply', true)
            ->assertJsonPath('data.reopened_at', null);
        $this->api('GET', "customer/tickets/{$closed->public_id}", token: $this->token)
            ->assertOk()
            ->assertJsonPath('data.can_reply', false);
    }

    public function test_a_reopened_ticket_shows_when_in_the_customer_json(): void
    {
        Notification::fake();
        $ticket = $this->ticket(['status' => 'resolved', 'assigned_to' => $this->handler->id, 'resolved_at' => now()->subHour(), 'resolution_note' => 'Selesai']);

        $this->reply($ticket)->assertCreated();

        $this->api('GET', "customer/tickets/{$ticket->public_id}", token: $this->token)
            ->assertOk()
            ->assertJsonPath('data.status', 'assigned')
            ->assertJsonPath('data.can_reply', true)
            ->assertJsonPath('data.reopened_at', $ticket->fresh()->reopened_at->toJSON());
    }
}
