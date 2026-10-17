<?php

namespace Tests\Feature\Ticketing;

use App\Models\Core\Company;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Ticketing\Models\Ticket;
use Modules\Ticketing\Models\TicketCategory;
use Spatie\Activitylog\Models\Activity;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Tests\TestCase;

/**
 * Taking a file off a ticket on the web follows the rule of the apps (TicketService::mayRemoveAttachment): the user
 * who uploaded it, and only while the ticket is still being worked. The permission alone is not enough: a manager
 * who is also a technician holds ticket.attachment.upload through the second role and must not clear other
 * people's files with it.
 */
class TicketAttachmentRemovalTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $uploader;

    private User $colleague;

    private User $both;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->seed(RolePermissionSeeder::class);
        app()['cache']->forget('spatie.permission.cache');

        $this->company = Company::factory()->create(['is_active' => true]);
        $this->uploader = $this->user('staff');
        $this->colleague = $this->user('staff');
        $this->both = $this->user('manager', 'technician');
        $this->admin = $this->user('admin');
    }

    public function test_the_uploader_takes_their_own_file_off_while_the_ticket_is_still_being_worked(): void
    {
        foreach (['open', 'assigned', 'on_progress'] as $status) {
            $ticket = $this->ticket(['status' => $status]);
            $file = $this->file($ticket, $this->uploader);

            $this->actingAs($this->uploader)
                ->delete(route('admin.tickets.attachments.destroy', [$ticket, $file->public_id]))
                ->assertRedirect()
                ->assertSessionHas('success', 'Lampiran berhasil dihapus.');

            $this->assertNull(Media::query()->find($file->id), $status);
        }

        $this->assertSame(3, Activity::query()->where('log_name', 'ticket')->where('description', 'attachment_removed')->count());
    }

    public function test_staff_cannot_take_off_a_file_someone_else_uploaded(): void
    {
        $ticket = $this->ticket();
        $file = $this->file($ticket, $this->uploader);

        $this->actingAs($this->colleague)
            ->delete(route('admin.tickets.attachments.destroy', [$ticket, $file->public_id]))
            ->assertForbidden();

        $this->assertNotNull(Media::query()->find($file->id));
    }

    public function test_a_manager_who_is_also_a_technician_cannot_take_off_other_peoples_files(): void
    {
        $this->assertTrue($this->both->can('ticket.attachment.upload'), 'the technician role is where the permission comes from');
        $ticket = $this->ticket();
        $theirs = $this->file($ticket, $this->uploader);
        $mine = $this->file($ticket, $this->both);

        $this->actingAs($this->both)
            ->delete(route('admin.tickets.attachments.destroy', [$ticket, $theirs->public_id]))
            ->assertForbidden();
        $this->assertNotNull(Media::query()->find($theirs->id));

        $this->actingAs($this->both)
            ->delete(route('admin.tickets.attachments.destroy', [$ticket, $mine->public_id]))
            ->assertRedirect();
        $this->assertNull(Media::query()->find($mine->id));
    }

    public function test_there_is_no_admin_override_and_a_file_without_a_known_uploader_stays(): void
    {
        $ticket = $this->ticket();
        $theirs = $this->file($ticket, $this->uploader);
        $legacy = $this->file($ticket, null);

        foreach ([$theirs, $legacy] as $file) {
            $this->actingAs($this->admin)
                ->delete(route('admin.tickets.attachments.destroy', [$ticket, $file->public_id]))
                ->assertForbidden();
            $this->assertNotNull(Media::query()->find($file->id));
        }
    }

    public function test_a_file_stays_once_the_ticket_is_resolved_or_closed(): void
    {
        foreach (['resolved', 'closed'] as $status) {
            $ticket = $this->ticket(['status' => $status]);
            $file = $this->file($ticket, $this->uploader);

            $this->actingAs($this->uploader)
                ->delete(route('admin.tickets.attachments.destroy', [$ticket, $file->public_id]))
                ->assertForbidden();

            $this->assertNotNull(Media::query()->find($file->id), $status);
        }
    }

    public function test_a_file_of_another_ticket_or_company_is_a_404(): void
    {
        $ticket = $this->ticket();
        $elsewhere = $this->file($this->ticket(), $this->uploader);
        $foreign = $this->ticket([], Company::factory()->create(['is_active' => true]));
        $foreignFile = $this->file($foreign, $this->uploader);

        $this->actingAs($this->uploader)
            ->delete(route('admin.tickets.attachments.destroy', [$ticket, $elsewhere->public_id]))
            ->assertNotFound();
        $this->actingAs($this->uploader)
            ->delete(route('admin.tickets.attachments.destroy', [$foreign, $foreignFile->public_id]))
            ->assertNotFound();

        $this->assertNotNull(Media::query()->find($elsewhere->id));
        $this->assertNotNull(Media::query()->find($foreignFile->id));
    }

    private function user(string ...$roles): User
    {
        $user = User::factory()->create(['company_id' => $this->company->id, 'email_verified_at' => now(), 'is_active' => true]);
        $user->assignRole(...$roles);

        return $user;
    }

    /** @param  array<string, mixed>  $overrides */
    private function ticket(array $overrides = [], ?Company $company = null): Ticket
    {
        $company ??= $this->company;
        $category = TicketCategory::query()->create([
            'company_id' => $company->id, 'name' => 'Support', 'code' => 'SUP-'.Str::random(6),
            'default_sla_hours' => 24, 'default_priority' => 'medium', 'is_active' => true,
        ]);

        return Ticket::query()->create([
            'company_id' => $company->id, 'code' => 'TKT-'.Str::random(10), 'title' => 'Internet down', 'description' => 'Offline',
            'source' => 'internal', 'category_id' => $category->id, 'status' => 'open', 'priority' => 'medium',
            'created_by' => $this->admin->id, 'sla_deadline' => now()->addDay(), ...$overrides,
        ]);
    }

    private function file(Ticket $ticket, ?User $uploader): Media
    {
        return $ticket->addMediaFromString('png')->usingFileName('site.png')
            ->withCustomProperties(['company_id' => $ticket->company_id, 'uploaded_by' => $uploader?->id])
            ->toMediaCollection('attachments');
    }
}
