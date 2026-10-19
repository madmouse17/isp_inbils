<?php

namespace Tests\Feature\Ticketing;

use App\Models\User;
use App\Support\OptimisticLock;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\Ticketing\Models\Ticket;
use Modules\Ticketing\Models\TicketCategory;
use Modules\Ticketing\Services\TicketService;
use RuntimeException;
use Spatie\Activitylog\Models\Activity;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Tests\TestCase;
use Tests\Traits\CreatesCompanyUser;
use Tests\Traits\RunsConcurrentWorkers;

/**
 * A ticket is edited and deleted under its row lock: a save made from an older copy is refused, a request that waited for
 * the ticket to leave its status finds the new status and changes nothing, a priority change moves the SLA deadlines
 * the way creation sets them, and an attachment whose transaction fails leaves no file behind.
 */
class TicketUpdateLockTest extends TestCase
{
    use CreatesCompanyUser;
    use RefreshDatabase;
    use RunsConcurrentWorkers;

    private User $admin;

    private TicketCategory $category;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->admin = $this->createCompanyUser();
        $this->category = TicketCategory::query()->create([
            'company_id' => $this->admin->company_id, 'name' => 'Support', 'code' => 'SUP',
            'default_sla_hours' => 8, 'default_response_hours' => 3, 'default_priority' => 'medium', 'is_active' => true,
        ]);
    }

    /** A ticket last saved five minutes ago, so a save made now is a different version of it (versions keep whole seconds). */
    private function ticket(string $status = 'open'): Ticket
    {
        $ticket = Ticket::query()->create([
            'company_id' => $this->admin->company_id, 'code' => 'TKT-'.fake()->unique()->numerify('######'), 'title' => 'as rendered',
            'description' => 'Offline', 'source' => 'internal', 'category_id' => $this->category->id, 'status' => $status,
            'priority' => 'medium', 'created_by' => $this->admin->id, 'sla_deadline' => now()->addHours(8), 'response_deadline' => now()->addHours(3),
        ]);
        DB::table('tickets')->where('id', $ticket->id)->update(['updated_at' => now()->subMinutes(5)]);

        return $ticket->fresh();
    }

    public function test_a_ticket_save_from_an_older_copy_is_refused(): void
    {
        $ticket = $this->ticket();
        $rendered = OptimisticLock::version($ticket);

        $this->actingAs($this->admin)
            ->put(route('admin.tickets.update', $ticket), ['title' => 'first save', OptimisticLock::FIELD => $rendered])
            ->assertRedirect(route('admin.tickets.index'))
            ->assertSessionHas('success', 'Tiket berhasil diubah.');

        $this->putJson(route('admin.tickets.update', $ticket), ['title' => 'second save', OptimisticLock::FIELD => $rendered])
            ->assertStatus(409)
            ->assertJsonPath('error', 'conflict')
            ->assertJsonPath('message', OptimisticLock::MESSAGE);

        // A page visit (X-Inertia) gets the redirect back with the message the toast and the banner show.
        $this->withHeaders(['X-Inertia' => 'true'])
            ->put(route('admin.tickets.update', $ticket), ['title' => 'third save', OptimisticLock::FIELD => $rendered])
            ->assertStatus(303)
            ->assertSessionHasErrors(['conflict' => OptimisticLock::MESSAGE]);

        $this->assertSame('first save', $ticket->fresh()->title, 'the refused saves changed nothing');
    }

    public function test_a_save_needs_the_version_the_form_was_rendered_from(): void
    {
        $ticket = $this->ticket();

        $this->actingAs($this->admin)
            ->putJson(route('admin.tickets.update', $ticket), ['title' => 'no version'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(OptimisticLock::FIELD);

        $this->putJson(route('admin.tickets.update', $ticket), ['title' => 'bad version', OptimisticLock::FIELD => '2026-10-18'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(OptimisticLock::FIELD);

        $this->assertSame('as rendered', $ticket->fresh()->title);
    }

    public function test_only_an_open_ticket_is_saved_and_only_a_closed_one_is_deleted(): void
    {
        $assigned = $this->ticket('assigned');
        $closed = $this->ticket('closed');
        $open = $this->ticket();

        $this->actingAs($this->admin)
            ->putJson(route('admin.tickets.update', $assigned), ['title' => 'late edit', OptimisticLock::FIELD => OptimisticLock::version($assigned)])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Tiket hanya bisa diubah saat berstatus terbuka.');
        $this->deleteJson(route('admin.tickets.destroy', $open))
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Tiket hanya bisa dihapus saat berstatus ditutup.');
        $this->delete(route('admin.tickets.destroy', $closed))->assertRedirect()->assertSessionHas('success', 'Tiket berhasil dihapus.');

        $this->assertSame('as rendered', $assigned->fresh()->title);
        $this->assertNotSoftDeleted($open);
        $this->assertSoftDeleted($closed);
    }

    public function test_two_saves_racing_on_one_ticket_let_exactly_one_win(): void
    {
        $ticket = $this->ticket();
        $rendered = OptimisticLock::version($ticket);
        $this->commitFixture();

        try {
            $results = $this->race([
                ['company_id' => $ticket->company_id, 'ticket_id' => $ticket->id, 'expected' => $rendered, 'title' => 'saved by A'],
                ['company_id' => $ticket->company_id, 'ticket_id' => $ticket->id, 'expected' => $rendered, 'title' => 'saved by B'],
            ], <<<'PHP'
                config(['logging.default' => 'null']);

                Modules\Ticketing\Services\TicketService::update(
                    Modules\Ticketing\Models\Ticket::query()->findOrFail($args['ticket_id']),
                    ['title' => $args['title']],
                    $args['expected'],
                );

                return ['saved' => $args['title']];
            PHP);

            $saved = array_values(array_filter($results, fn (array $result): bool => $result['ok']));
            $refused = array_values(array_filter($results, fn (array $result): bool => ! $result['ok']));

            $this->assertCount(1, $saved, json_encode($results));
            $this->assertCount(1, $refused, json_encode($results));
            $this->assertSame(409, $refused[0]['status']);
            $this->assertSame(OptimisticLock::MESSAGE, $refused[0]['message']);
            $this->assertSame($saved[0]['result']['saved'], Ticket::withoutCompany()->findOrFail($ticket->id)->title, 'the winner is what the row holds');
        } finally {
            $this->purgeCompany($ticket->company_id);
        }
    }

    public function test_an_update_that_waited_for_assignment_is_refused(): void
    {
        $ticket = $this->ticket();
        $rendered = OptimisticLock::version($ticket);
        $this->commitFixture();

        try {
            $results = $this->raceAgainstAnAssigner($ticket, <<<'PHP'
                Modules\Ticketing\Services\TicketService::update($stale, ['title' => 'late edit'], $args['expected']);

                return ['updated' => true];
            PHP, $rendered);

            $this->assertSame(['assigned' => true], $results[0]['result']);
            $this->assertFalse($results[1]['ok'], json_encode($results));
            $this->assertSame(422, $results[1]['status']);
            $this->assertSame('Tiket hanya bisa diubah saat berstatus terbuka.', $results[1]['message']);

            $row = Ticket::withoutCompany()->findOrFail($ticket->id);
            $this->assertSame('assigned', $row->status);
            $this->assertSame('as rendered', $row->title, 'no edit lands on a ticket that was already assigned');
        } finally {
            $this->purgeCompany($ticket->company_id);
        }
    }

    public function test_a_delete_that_waited_for_a_reopen_is_refused(): void
    {
        $ticket = $this->ticket('closed');
        $this->commitFixture();

        try {
            $results = $this->raceAgainstAnAssigner($ticket, <<<'PHP'
                Modules\Ticketing\Services\TicketService::destroy($stale);

                return ['deleted' => true];
            PHP);

            $this->assertSame(['assigned' => true], $results[0]['result']);
            $this->assertFalse($results[1]['ok'], json_encode($results));
            $this->assertSame(422, $results[1]['status']);
            $this->assertSame('Tiket hanya bisa dihapus saat berstatus ditutup.', $results[1]['message']);

            $row = Ticket::withoutCompany()->withTrashed()->findOrFail($ticket->id);
            $this->assertSame('assigned', $row->status);
            $this->assertNull($row->deleted_at, 'a ticket that is no longer closed is never removed by a request that saw it closed');
        } finally {
            $this->purgeCompany($ticket->company_id);
        }
    }

    public function test_raising_priority_on_an_open_ticket_moves_its_deadlines_from_creation(): void
    {
        $this->travelTo(Carbon::parse('2026-03-02 09:00:00'));
        $ticket = $this->ticket();
        $this->travelTo(Carbon::parse('2026-03-02 11:00:00'));
        $rendered = OptimisticLock::version($ticket);

        $this->actingAs($this->admin)
            ->put(route('admin.tickets.update', $ticket), ['title' => 'as rendered', 'priority' => 'urgent', OptimisticLock::FIELD => $rendered])
            ->assertRedirect();

        $saved = $ticket->fresh();
        $this->assertSame('2026-03-02 13:00:00', $saved->sla_deadline->toDateTimeString(), 'created 09:00 + 8 h halved to 4 h, not counted from the edit at 11:00');
        $this->assertSame('2026-03-02 11:00:00', $saved->response_deadline->toDateTimeString(), 'created 09:00 + 3 h halved to 2 h');

        $this->travelTo(Carbon::parse('2026-03-02 12:00:00'));
        $this->put(route('admin.tickets.update', $ticket), ['title' => 'retitled', 'priority' => 'urgent', OptimisticLock::FIELD => OptimisticLock::version($saved)])->assertRedirect();
        $this->assertSame('2026-03-02 13:00:00', $ticket->fresh()->sla_deadline->toDateTimeString(), 'a save that keeps the priority leaves the deadlines alone');

        $this->travelTo(Carbon::parse('2026-03-02 13:00:00'));
        $this->put(route('admin.tickets.update', $ticket), ['title' => 'retitled', 'priority' => 'low', OptimisticLock::FIELD => OptimisticLock::version($ticket->fresh())])->assertRedirect();
        $this->assertSame('2026-03-02 17:00:00', $ticket->fresh()->sla_deadline->toDateTimeString(), 'lowering it goes back to the full 8 h from creation');
    }

    public function test_the_same_file_uploaded_twice_from_the_web_is_stored_once(): void
    {
        $ticket = $this->ticket();
        $file = fn (): UploadedFile => UploadedFile::fake()->createWithContent('proof.pdf', "%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF\n");

        $this->actingAs($this->admin)->post(route('admin.tickets.attachments.store', $ticket), ['file' => $file()])->assertRedirect();
        $this->travel(11)->seconds();
        $this->post(route('admin.tickets.attachments.store', $ticket), ['file' => $file()])->assertRedirect();

        $this->assertCount(1, $ticket->fresh()->getMedia('attachments'));
        $this->assertSame(1, Activity::query()->where('log_name', 'ticket')->where('description', 'attachment_uploaded')->count());
    }

    public function test_a_rolled_back_attachment_upload_leaves_no_file(): void
    {
        $ticket = $this->ticket();
        $file = UploadedFile::fake()->createWithContent('proof.pdf', "%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF\n");
        // A listener cannot be removed on its own (flushEventListeners would also drop the app's activity hooks), so it is switched off.
        $failing = true;
        Activity::creating(function () use (&$failing): void {
            if ($failing) {
                throw new RuntimeException('audit store down');
            }
        });

        try {
            TicketService::addAttachment($ticket, $this->admin, ['file' => $file]);
            $this->fail('the upload should have failed');
        } catch (RuntimeException $exception) {
            $this->assertSame('audit store down', $exception->getMessage());
        } finally {
            $failing = false;
        }

        $this->assertSame(0, Media::query()->where('collection_name', 'attachments')->count());
        $this->assertSame([], Storage::disk('local')->allFiles(), 'the stored file went with the rolled back row');
    }

    /**
     * Worker 0 locks the ticket, assigns it and holds the lock for 800 ms. Worker 1 loads the ticket first, starts 200 ms
     * later and runs $change with that stale copy in $stale.
     *
     * @return list<array{ok: bool, status?: int, result?: mixed, message?: string}>
     */
    private function raceAgainstAnAssigner(Ticket $ticket, string $change, ?string $expected = null): array
    {
        $payload = ['company_id' => $ticket->company_id, 'ticket_id' => $ticket->id, 'expected' => $expected];

        return $this->race([
            [...$payload, 'role' => 'assigner'],
            [...$payload, 'role' => 'changer'],
        ], <<<PHP
            config(['logging.default' => 'null']);

            if (\$args['role'] === 'assigner') {
                Illuminate\\Support\\Facades\\DB::transaction(function () use (\$args) {
                    Modules\\Ticketing\\Models\\Ticket::withoutCompany()->whereKey(\$args['ticket_id'])->lockForUpdate()->firstOrFail()
                        ->update(['status' => 'assigned']);
                    usleep(800000);
                });

                return ['assigned' => true];
            }

            \$stale = Modules\\Ticketing\\Models\\Ticket::query()->findOrFail(\$args['ticket_id']);
            usleep(200000);

            {$change}
            PHP);
    }
}
