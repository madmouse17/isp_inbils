<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Customer;

use App\Models\Core\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Modules\Ticketing\Models\Ticket;
use Modules\Ticketing\Models\TicketCategory;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use Tests\Traits\InteractsWithMobileApi;

/**
 * K14: "Masalah sudah beres?". The customer rates a resolved ticket 1 to 5, which closes it, or a closed ticket nobody rated.
 * A ticket is rated once, only the customer's own ticket can be, and a retry of the same request answers the same.
 */
class TicketConfirmationTest extends TestCase
{
    use InteractsWithMobileApi;
    use RefreshDatabase;

    private const RATING_MIGRATION = 'Modules/Ticketing/database/migrations/2026_11_02_100100_add_customer_rating_to_tickets_table.php';

    private User $customerUser;

    private Customer $customer;

    private string $token;

    private User $handler;

    private TicketCategory $category;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->customerUser, $this->customer] = $this->customerLogin();
        $this->token = $this->issueToken($this->customerUser, 'customer');
        $this->handler = User::factory()->create(['company_id' => $this->customerUser->company_id, 'is_active' => true, 'name' => 'Budi Santoso']);
        $this->grantRole($this->handler, 'technician');
        $this->category = TicketCategory::query()->create([
            'company_id' => $this->customerUser->company_id, 'name' => 'Support', 'code' => 'SUP-'.Str::random(6),
            'default_sla_hours' => 24, 'default_priority' => 'medium', 'is_active' => true,
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function ticket(array $overrides = []): Ticket
    {
        return Ticket::query()->create($overrides + [
            'company_id' => $this->customerUser->company_id, 'code' => 'TKT-'.Str::upper(Str::random(8)), 'title' => 'Internet mati',
            'description' => 'Lampu LOS merah', 'source' => 'customer', 'category_id' => $this->category->id, 'status' => 'open',
            'priority' => 'medium', 'customer_id' => $this->customer->id, 'created_by' => $this->customerUser->id,
            'assigned_to' => $this->handler->id, 'sla_deadline' => now()->addDay(),
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function resolved(array $overrides = []): Ticket
    {
        return $this->ticket($overrides + ['status' => 'resolved', 'resolved_at' => now()->subHour(), 'resolution_note' => 'Kabel diganti']);
    }

    /** @param array<string, mixed> $overrides */
    private function closed(array $overrides = []): Ticket
    {
        return $this->resolved($overrides + ['status' => 'closed', 'closed_at' => now()->subDay(), 'category_name_snapshot' => 'Support', 'assignee_name_snapshot' => 'Budi Santoso']);
    }

    /** @param array<string, mixed> $payload */
    private function confirm(Ticket $ticket, array $payload, ?string $token = null): TestResponse
    {
        return $this->api('POST', "customer/tickets/{$ticket->public_id}/confirmation", $payload, $token ?? $this->token);
    }

    public function test_confirming_a_resolved_ticket_closes_it_together_with_the_rating(): void
    {
        Notification::fake();
        $ticket = $this->resolved();

        $this->confirm($ticket, ['rating' => 5, 'comment' => 'Cepat dan ramah'])
            ->assertOk()
            ->assertJsonPath('data.id', $ticket->public_id)
            ->assertJsonPath('data.status', 'closed')
            ->assertJsonPath('data.rating.value', 5)
            ->assertJsonPath('data.rating.comment', 'Cepat dan ramah')
            ->assertJsonPath('data.can_confirm', false)
            ->assertJsonPath('data.can_rate', false)
            ->assertJsonPath('data.can_reply', false)
            ->assertJsonPath('data.handler_first_name', 'Budi');

        $closed = $ticket->fresh();
        $this->assertSame('closed', $closed->status);
        $this->assertNotNull($closed->closed_at);
        $this->assertSame(5, $closed->customer_rating);
        $this->assertSame('Cepat dan ramah', $closed->customer_rating_comment);
        $this->assertNotNull($closed->customer_rated_at);
        $this->assertSame('Support', $closed->category_name_snapshot, 'it closes the way a checker closes it: with the names it closed with');
        $this->assertSame('Budi Santoso', $closed->assignee_name_snapshot);

        $this->assertSame(1, Activity::query()->where('log_name', 'ticket')->where('event', 'closed')->count());
        $audit = Activity::query()->where('log_name', 'ticket')->where('event', 'customer_confirmed')->sole();
        $this->assertSame(5, $audit->properties['rating']);
        $this->assertTrue($audit->properties['closed']);
        Notification::assertNothingSent();
    }

    public function test_a_closed_ticket_that_was_never_rated_only_takes_the_rating(): void
    {
        $ticket = $this->closed();
        $closedAt = $ticket->closed_at;

        $this->confirm($ticket, ['rating' => 3])
            ->assertOk()
            ->assertJsonPath('data.status', 'closed')
            ->assertJsonPath('data.rating.value', 3)
            ->assertJsonPath('data.rating.comment', null);

        $this->assertEquals($closedAt, $ticket->fresh()->closed_at, 'it was closed already');
        $this->assertSame(0, Activity::query()->where('log_name', 'ticket')->where('event', 'closed')->count());
        $this->assertFalse(Activity::query()->where('log_name', 'ticket')->where('event', 'customer_confirmed')->sole()->properties['closed']);
    }

    public function test_a_ticket_is_rated_once_whether_it_is_closed_or_came_back_resolved(): void
    {
        $rated = ['customer_rating' => 2, 'customer_rating_comment' => 'Lambat', 'customer_rated_at' => now()->subHours(2)];

        foreach (['closed' => $this->closed($rated), 'resolved again after a reopening' => $this->resolved($rated)] as $why => $ticket) {
            $before = $ticket->status;

            $this->confirm($ticket, ['rating' => 5, 'comment' => 'Sekarang bagus'])
                ->assertStatus(409)
                ->assertJsonPath('code', 'ticket_already_rated')
                ->assertJsonPath('message', 'Tiket ini sudah dinilai.');

            $this->assertSame(2, $ticket->fresh()->customer_rating, $why);
            $this->assertSame('Lambat', $ticket->fresh()->customer_rating_comment, $why);
            $this->assertSame($before, $ticket->fresh()->status, $why);
        }
    }

    public function test_a_ticket_that_is_not_finished_is_a_422_with_ticket_not_resolved(): void
    {
        foreach (['open', 'assigned', 'on_progress'] as $status) {
            $ticket = $this->ticket(['status' => $status]);

            $this->confirm($ticket, ['rating' => 4])
                ->assertStatus(422)
                ->assertJsonPath('code', 'ticket_not_resolved')
                ->assertJsonPath('message', 'Tiket belum diselesaikan.');

            $this->assertNull($ticket->fresh()->customer_rating, $status);
            $this->assertSame($status, $ticket->fresh()->status);
        }
    }

    public function test_a_retry_with_the_same_key_gets_the_first_answer_and_a_new_key_gets_the_409(): void
    {
        $ticket = $this->resolved();
        $key = (string) Str::uuid();
        $send = fn (string $withKey): TestResponse => $this->api('POST', "customer/tickets/{$ticket->public_id}/confirmation", ['rating' => 4], $this->token, ['Idempotency-Key' => $withKey]);

        $first = $send($key)->assertOk();
        $replay = $send($key)->assertOk()->assertHeader('Idempotent-Replayed', 'true');
        $replay->assertExactJson($first->json());

        $this->assertSame(1, Activity::query()->where('log_name', 'ticket')->where('event', 'customer_confirmed')->count());
        $send((string) Str::uuid())->assertStatus(409)->assertJsonPath('code', 'ticket_already_rated');
        $this->assertSame(4, $ticket->fresh()->customer_rating);
    }

    public function test_the_rating_is_a_whole_number_from_one_to_five_and_the_comment_has_a_limit(): void
    {
        $ticket = $this->resolved();

        foreach ([[], ['rating' => 0], ['rating' => 6], ['rating' => 'bagus'], ['rating' => 3.5], ['rating' => 5, 'comment' => str_repeat('x', 1001)]] as $payload) {
            $this->confirm($ticket, $payload)->assertStatus(422)->assertJsonPath('code', 'validation_failed');
        }

        $this->assertSame('resolved', $ticket->fresh()->status);
        $this->assertNull($ticket->fresh()->customer_rating);

        $this->confirm($ticket, ['rating' => 5, 'comment' => str_repeat('x', 1000)])->assertOk();
    }

    public function test_the_ticket_of_another_customer_is_not_found(): void
    {
        [, $stranger] = $this->customerLogin($this->customerUser->company);
        $theirs = $this->resolved(['customer_id' => $stranger->id]);

        $this->confirm($theirs, ['rating' => 5])->assertNotFound()->assertJsonPath('code', 'not_found');

        $this->assertSame('resolved', $theirs->fresh()->status);
        $this->assertNull($theirs->fresh()->customer_rating);
    }

    public function test_a_ticket_of_another_company_is_not_found(): void
    {
        [$otherUser, $otherCustomer] = $this->customerLogin();
        $theirs = Ticket::query()->create([
            'company_id' => $otherUser->company_id, 'code' => 'TKT-OTHER', 'title' => 'Lain', 'source' => 'customer',
            'category_id' => TicketCategory::query()->create([
                'company_id' => $otherUser->company_id, 'name' => 'Lain', 'code' => 'LN-'.Str::random(5), 'default_sla_hours' => 24, 'default_priority' => 'medium', 'is_active' => true,
            ])->id,
            'status' => 'resolved', 'priority' => 'medium', 'customer_id' => $otherCustomer->id, 'created_by' => $otherUser->id, 'sla_deadline' => now()->addDay(),
        ]);

        $this->confirm($theirs, ['rating' => 5])->assertNotFound();
        $this->assertSame('resolved', $theirs->fresh()->status);
    }

    public function test_a_technician_token_and_a_missing_token_are_refused(): void
    {
        $ticket = $this->resolved();
        $technician = $this->technicianLogin($this->customerUser->company);
        $technicianToken = $this->issueToken($technician, 'technician');

        $this->confirm($ticket, ['rating' => 5], $technicianToken)->assertForbidden();
        $this->api('POST', "customer/tickets/{$ticket->public_id}/confirmation", ['rating' => 5])->assertUnauthorized();
        $this->assertSame('resolved', $ticket->fresh()->status);
    }

    public function test_the_permission_to_rate_is_needed_and_the_buttons_follow_it(): void
    {
        $ticket = $this->resolved();
        $this->companyRoles((int) $this->customerUser->company_id)['customer']->revokePermissionTo('evaluation.customer.submit');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->confirm($ticket, ['rating' => 5])->assertForbidden();
        $this->api('GET', "customer/tickets/{$ticket->public_id}", token: $this->token)
            ->assertOk()
            ->assertJsonPath('data.can_confirm', false)
            ->assertJsonPath('data.can_rate', false);
        $this->assertSame('resolved', $ticket->fresh()->status);
    }

    public function test_the_ticket_json_says_which_answer_is_on_offer(): void
    {
        $resolved = $this->resolved();
        $closed = $this->closed();
        $rated = $this->closed(['customer_rating' => 5, 'customer_rating_comment' => 'Mantap', 'customer_rated_at' => now()->subDay()]);
        $open = $this->ticket(['status' => 'on_progress']);
        $json = fn (Ticket $ticket): array => $this->api('GET', "customer/tickets/{$ticket->public_id}", token: $this->token)->assertOk()->json('data');

        $this->assertSame([true, false, null], [$json($resolved)['can_confirm'], $json($resolved)['can_rate'], $json($resolved)['rating']]);
        $this->assertSame([false, true, null], [$json($closed)['can_confirm'], $json($closed)['can_rate'], $json($closed)['rating']]);
        $this->assertSame([false, false], [$json($rated)['can_confirm'], $json($rated)['can_rate']]);
        $this->assertSame(['value' => 5, 'comment' => 'Mantap'], array_intersect_key($json($rated)['rating'], ['value' => 1, 'comment' => 1]));
        $this->assertNotNull($json($rated)['rating']['rated_at']);
        $this->assertSame([false, false], [$json($open)['can_confirm'], $json($open)['can_rate']]);

        $list = collect($this->api('GET', 'customer/tickets', token: $this->token)->assertOk()->json('data'))->keyBy('id');
        $this->assertTrue($list[$resolved->public_id]['can_confirm']);
        $this->assertTrue($list[$closed->public_id]['can_rate']);
    }

    public function test_the_technician_never_sees_the_customers_rating(): void
    {
        $ticket = $this->closed(['customer_rating' => 1, 'customer_rating_comment' => 'Buruk sekali', 'customer_rated_at' => now()->subDay()]);
        $technician = $this->technicianLogin($this->customerUser->company);
        $ticket->forceFill(['assigned_to' => $technician->id])->save();

        $json = (string) $this->api('GET', "technician/tickets/{$ticket->public_id}", token: $this->issueToken($technician, 'technician'))->assertOk()->getContent();

        $this->assertStringNotContainsString('Buruk sekali', $json);
        $this->assertStringNotContainsString('"rating"', $json);
        $this->assertStringNotContainsString('can_confirm', $json);
    }

    /** MySQL commits on DDL: the test puts the columns and the index back before it ends. */
    public function test_the_rating_columns_and_their_index_drop_and_return(): void
    {
        $migration = require base_path(self::RATING_MIGRATION);
        $columns = ['customer_rating', 'customer_rating_comment', 'customer_rated_at'];

        try {
            foreach ($columns as $column) {
                $this->assertTrue(Schema::hasColumn('tickets', $column), $column);
            }
            $this->assertTrue(Schema::hasIndex('tickets', ['company_id', 'customer_rated_at', 'customer_rating']), 'the satisfaction figure of a period reads it');

            $migration->down();
            foreach ($columns as $column) {
                $this->assertFalse(Schema::hasColumn('tickets', $column), $column);
            }
            $this->assertFalse(Schema::hasIndex('tickets', ['company_id', 'customer_rated_at', 'customer_rating']));

            $migration->up();
            $migration->up();
            foreach ($columns as $column) {
                $this->assertTrue(Schema::hasColumn('tickets', $column), $column);
            }
            $this->assertTrue(Schema::hasIndex('tickets', ['company_id', 'customer_rated_at', 'customer_rating']), 'it can run twice');
        } finally {
            if (! Schema::hasColumn('tickets', 'customer_rating') || ! Schema::hasIndex('tickets', ['company_id', 'customer_rated_at', 'customer_rating'])) {
                $migration->up();
            }
        }
    }
}
