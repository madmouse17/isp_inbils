<?php

declare(strict_types=1);

namespace Tests\Feature\Ticketing;

use App\Models\Core\Customer;
use App\Models\Core\Location;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Ticketing\Models\Ticket;
use Modules\Ticketing\Models\TicketCategory;
use Modules\Ticketing\Models\TicketComment;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use Tests\Traits\CreatesCompanyUser;
use Tests\Traits\ParsesCsvDownloads;

/**
 * F6-U12 and the predicates behind F6-U18: the queue chips of the ticket list (Belum ditugaskan, Hampir terlampaui,
 * Pelanggan membalas), the sort by response deadline, the two export columns, and a list whose cost does not follow
 * its rows. Every moment is worked out by hand from a frozen clock of 2026-03-02 09:00:00.
 */
class TicketQueueFiltersTest extends TestCase
{
    use CreatesCompanyUser;
    use ParsesCsvDownloads;
    use RefreshDatabase;

    private User $admin;

    private TicketCategory $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-03-02 09:00:00'));
        $this->admin = $this->createCompanyUser();
        $this->category = TicketCategory::query()->create([
            'company_id' => $this->admin->company_id, 'name' => 'Gangguan', 'code' => 'CAT-'.Str::random(6),
            'default_sla_hours' => 24, 'default_priority' => 'medium', 'is_active' => true,
        ]);
    }

    public function test_unassigned_filter_lists_open_tickets_without_assignee(): void
    {
        $handler = $this->staff();
        $this->ticket('TKT-FREE');
        $this->ticket('TKT-TAKEN', ['status' => 'assigned', 'assigned_to' => $handler->id]);
        $this->ticket('TKT-DONE', ['status' => 'resolved', 'resolved_at' => now()]);
        $this->ticket('TKT-SHUT', ['status' => 'closed', 'closed_at' => now()]);

        $this->assertSame(['TKT-FREE'], $this->listed(['unassigned' => 1]));
        $this->assertSame(['TKT-DONE', 'TKT-FREE', 'TKT-SHUT', 'TKT-TAKEN'], $this->listed());
        $this->assertSame('1', $this->get(route('admin.tickets.index', ['unassigned' => 1]))->viewData('page')['props']['filters']['unassigned']);
        $this->assertSame(['TKT-FREE'], $this->exported(['unassigned' => 1]));
    }

    public function test_due_soon_matches_unanswered_response_or_resolution_within_30_minutes(): void
    {
        $answered = ['first_response_at' => now()->subMinutes(5), 'first_response_source' => 'reply'];
        $this->ticket('TKT-RESP-SOON', ['response_deadline' => now()->addMinutes(20)]);
        $this->ticket('TKT-EDGE', ['response_deadline' => now()->addMinutes(30)]);
        $this->ticket('TKT-FIX-SOON', ['status' => 'on_progress', ...$answered, 'sla_deadline' => now()->addMinutes(25)]);
        $this->ticket('TKT-ANSWERED', ['response_deadline' => now()->addMinutes(20), ...$answered]);
        $this->ticket('TKT-FAR', ['response_deadline' => now()->addMinutes(45)]);
        $this->ticket('TKT-LATE', ['response_deadline' => now()->subMinutes(10)]);
        $this->ticket('TKT-SHUT', ['status' => 'closed', 'sla_deadline' => now()->addMinutes(10), 'response_deadline' => now()->addMinutes(10)]);
        $this->ticket('TKT-DONE', ['status' => 'resolved', 'sla_deadline' => now()->addMinutes(10), 'response_deadline' => now()->addMinutes(10)]);

        $this->assertSame(['TKT-EDGE', 'TKT-FIX-SOON', 'TKT-RESP-SOON'], $this->listed(['due_soon' => 1]));
        $this->assertSame(['TKT-EDGE', 'TKT-FIX-SOON', 'TKT-RESP-SOON'], $this->exported(['due_soon' => 1]));
        $this->assertSame(
            ['TKT-EDGE', 'TKT-FAR', 'TKT-FIX-SOON', 'TKT-RESP-SOON'],
            Ticket::query()->dueSoon(60)->orderBy('code')->pluck('code')->all(),
            'the window is the argument of the scope',
        );
    }

    public function test_awaiting_reply_lists_tickets_whose_last_public_comment_is_the_customers(): void
    {
        [$user, $customer] = $this->portalCustomer();
        [$otherUser] = $this->portalCustomer();
        $mine = ['customer_id' => $customer->id];

        $this->comment($this->ticket('TKT-WAITING', $mine), $user);

        $answered = $this->ticket('TKT-ANSWERED', $mine);
        $this->comment($answered, $user);
        $this->comment($answered, $this->admin);

        $again = $this->ticket('TKT-AGAIN', $mine);
        $this->comment($again, $this->admin);
        $this->comment($again, $user);

        $noted = $this->ticket('TKT-NOTE-ONLY', $mine);
        $this->comment($noted, $user);
        $this->comment($noted, $this->admin, internal: true);

        $this->comment($this->ticket('TKT-DONE', $mine + ['status' => 'resolved', 'resolved_at' => now()]), $user);
        $this->ticket('TKT-SILENT', $mine);
        $this->comment($this->ticket('TKT-STRANGER', $mine), $otherUser);

        $retracted = $this->ticket('TKT-RETRACTED', $mine);
        $this->comment($retracted, $user);
        $this->comment($retracted, $this->admin)->delete();

        $withdrawn = $this->ticket('TKT-WITHDRAWN', $mine);
        $this->comment($withdrawn, $this->admin);
        $this->comment($withdrawn, $user)->delete();

        // A customer record without an app login has no account to reply from.
        $this->comment($this->ticket('TKT-NO-LOGIN', ['customer_id' => Customer::factory()->create(['company_id' => $this->admin->company_id])->id]), $user);

        $expected = ['TKT-AGAIN', 'TKT-NOTE-ONLY', 'TKT-RETRACTED', 'TKT-WAITING'];
        $this->assertSame($expected, $this->listed(['awaiting_reply' => 1]));
        $this->assertSame($expected, $this->exported(['awaiting_reply' => 1]));
    }

    public function test_handler_without_assign_keeps_own_scope_under_new_filters(): void
    {
        $handlerRole = Role::firstOrCreate(['name' => 'ticket-queue-handler', 'guard_name' => 'web']);
        $handlerRole->syncPermissions(['ticket.view', 'ticket.start', 'ticket.resolve', 'ticket.export']);
        $handler = $this->staff(null);
        $handler->assignRole($handlerRole);
        $other = $this->staff();
        $soon = ['response_deadline' => now()->addMinutes(10)];

        $this->ticket('TKT-OWN-SOON', ['status' => 'assigned', 'assigned_to' => $handler->id, ...$soon]);
        $this->ticket('TKT-OTHER-SOON', ['status' => 'assigned', 'assigned_to' => $other->id, ...$soon]);
        $this->ticket('TKT-FREE-SOON', $soon);

        $this->assertSame(['TKT-OWN-SOON'], $this->listed(['due_soon' => 1], $handler));
        $this->assertSame([], $this->listed(['unassigned' => 1], $handler), 'without ticket.assign the queue of unassigned tickets is empty');
        $this->assertSame([], $this->exported(['unassigned' => 1], $handler));
        $this->assertSame(['TKT-FREE-SOON'], $this->listed(['unassigned' => 1], $this->admin));
    }

    public function test_sort_by_response_deadline(): void
    {
        $this->ticket('TKT-3H', ['response_deadline' => now()->addHours(3)]);
        $this->ticket('TKT-1H', ['response_deadline' => now()->addHour()]);
        $this->ticket('TKT-2H', ['response_deadline' => now()->addHours(2)]);

        $this->assertSame(['TKT-1H', 'TKT-2H', 'TKT-3H'], $this->listed(['sort_by' => 'response_deadline', 'sort_dir' => 'asc'], sorted: false));
        $this->assertSame(['TKT-3H', 'TKT-2H', 'TKT-1H'], $this->listed(['sort_by' => 'response_deadline', 'sort_dir' => 'desc'], sorted: false));
    }

    public function test_export_has_assignee_and_response_columns(): void
    {
        $handler = $this->staff();
        $handler->forceFill(['name' => 'Budi Santoso'])->save();
        $this->ticket('TKT-HANDLED', ['status' => 'assigned', 'assigned_to' => $handler->id, 'response_deadline' => '2026-03-02 11:00:00']);
        $this->ticket('TKT-FREE');

        $records = collect($this->csvRecords($this->actingAs($this->admin)->get(route('admin.tickets.export', ['format' => 'csv']))))->keyBy('Kode');

        $this->assertSame(['Kode', 'Judul', 'Prioritas', 'Status', 'Pelanggan', 'Petugas', 'Respons sebelum', 'Dibuat'], array_keys($records['TKT-HANDLED']));
        $this->assertSame('Budi Santoso', $records['TKT-HANDLED']['Petugas']);
        $this->assertSame('2026-03-02 11:00:00', $records['TKT-HANDLED']['Respons sebelum']);
        $this->assertSame('', $records['TKT-FREE']['Petugas'], 'an empty cell: a bare hyphen would be written as \'- by the formula guard of the CSV');
        $this->assertSame('', $records['TKT-FREE']['Respons sebelum']);
    }

    public function test_index_query_count_does_not_grow_with_rows(): void
    {
        $this->richRows(2);
        $this->listed();

        $before = $this->queryCount(fn (): array => $this->listed());
        $this->richRows(8);
        $after = $this->queryCount(fn (): array => $this->listed());

        $this->assertSame($before, $after, 'ten rows cost what two rows cost');
        $this->assertCount(10, $this->listed());

        $row = $this->get(route('admin.tickets.index', ['per_page' => 50]))->viewData('page')['props']['tickets']['data'][0];
        $this->assertArrayNotHasKey('attachments', $row, 'the list does not read the files, so it does not load them');
        $this->assertSame(['id', 'name'], array_keys($row['assignee']), 'a name is all the list reads of the handler');
    }

    /** @param  array<string, mixed>  $overrides */
    private function ticket(string $code, array $overrides = []): Ticket
    {
        return Ticket::query()->create($overrides + [
            'company_id' => $this->admin->company_id, 'code' => $code, 'title' => 'Internet mati '.$code, 'source' => 'customer',
            'category_id' => $this->category->id, 'status' => 'open', 'priority' => 'medium', 'created_by' => $this->admin->id,
            'sla_deadline' => now()->addDay(),
        ]);
    }

    private function staff(?string $role = 'technician'): User
    {
        $user = User::factory()->create(['company_id' => $this->admin->company_id, 'email_verified_at' => now(), 'is_active' => true]);
        if ($role !== null) {
            $user->assignRole($role);
        }

        return $user;
    }

    /** @return array{0: User, 1: Customer} a customer of the company with the app login the portal gives them */
    private function portalCustomer(): array
    {
        $user = User::factory()->create(['company_id' => $this->admin->company_id, 'email_verified_at' => now(), 'is_active' => true]);
        $user->assignRole('customer');

        return [$user, Customer::factory()->create(['company_id' => $this->admin->company_id, 'user_id' => $user->id])];
    }

    private function comment(Ticket $ticket, User $author, bool $internal = false): TicketComment
    {
        return TicketComment::query()->create(['ticket_id' => $ticket->id, 'author_id' => $author->id, 'body' => 'Pesan', 'is_internal' => $internal]);
    }

    /**
     * What the index lists for the query, as ticket codes: sorted unless the order is the point.
     *
     * @param  array<string, mixed>  $query
     * @return list<string>
     */
    private function listed(array $query = [], ?User $as = null, bool $sorted = true): array
    {
        $rows = $this->actingAs($as ?? $this->admin)
            ->get(route('admin.tickets.index', $query + ['per_page' => 50]))
            ->assertOk()->viewData('page')['props']['tickets']['data'];
        $codes = array_column($rows, 'code');

        if ($sorted) {
            sort($codes);
        }

        return $codes;
    }

    /**
     * What the CSV export holds for the same query, as sorted ticket codes.
     *
     * @param  array<string, mixed>  $query
     * @return list<string>
     */
    private function exported(array $query, ?User $as = null): array
    {
        $codes = array_column($this->csvRecords($this->actingAs($as ?? $this->admin)->get(route('admin.tickets.export', ['format' => 'csv', ...$query]))), 'Kode');
        sort($codes);

        return $codes;
    }

    /** Rows with everything the list could load per row: portal account, coverage area, handler, creator, place. */
    private function richRows(int $count): void
    {
        foreach (range(1, $count) as $ignored) {
            [$user, $customer] = $this->portalCustomer();
            $customer->forceFill(['area_coverage_id' => Location::factory()->create(['company_id' => $this->admin->company_id])->id])->save();

            $this->ticket('TKT-'.Str::upper(Str::random(8)), [
                'customer_id' => $customer->id, 'assigned_to' => $this->staff()->id, 'status' => 'assigned', 'created_by' => $user->id,
                'location_id' => Location::factory()->create(['company_id' => $this->admin->company_id])->id,
                'response_deadline' => now()->addHours(2),
            ]);
        }
    }

    /** @param  callable(): mixed  $request */
    private function queryCount(callable $request): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $request();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }
}
