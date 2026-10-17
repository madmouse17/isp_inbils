<?php

namespace Tests\Feature\Ticketing;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Modules\Ticketing\Models\Ticket;
use Modules\Ticketing\Models\TicketCategory;
use Modules\Ticketing\Services\TicketService;
use Tests\TestCase;
use Tests\Traits\CreatesCompanyUser;
use Tests\Traits\WithoutAppOnlyRedirect;

/**
 * Spec .scratch/opaque-ids K3 on the ticket actions that take a body: a technician the ticket page hides the ticket
 * from (it is not theirs to handle) gets the 404 of a ticket that does not exist for a comment or a file, and nothing
 * is saved. Commenting and attaching keep working for the handler.
 */
class TicketHiddenFromUserTest extends TestCase
{
    use CreatesCompanyUser;
    use RefreshDatabase;
    use WithoutAppOnlyRedirect;

    private User $admin;

    private User $technician;

    private Ticket $ticket;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('public');
        $this->seed(RolePermissionSeeder::class);
        app()['cache']->forget('spatie.permission.cache');

        $this->admin = $this->createCompanyUser();
        $this->technician = User::factory()->create(['company_id' => $this->admin->company_id, 'is_active' => true, 'email_verified_at' => now()]);
        $this->technician->assignRole('technician');
        $this->ticket = $this->makeOpenTicket();
    }

    public function test_a_technician_the_ticket_is_hidden_from_cannot_comment_on_it_or_attach_a_file(): void
    {
        $this->actingAs($this->technician)->get(route('admin.tickets.show', $this->ticket))->assertNotFound();

        $this->post(route('admin.tickets.comments.store', $this->ticket), ['body' => 'Seen it', 'is_internal' => false])->assertNotFound();
        $this->post(route('admin.tickets.attachments.store', $this->ticket), ['file' => UploadedFile::fake()->create('proof.pdf', 10, 'application/pdf')])->assertNotFound();

        $this->assertSame(0, $this->ticket->comments()->count());
        $this->assertCount(0, $this->ticket->fresh()->getMedia('attachments'));
    }

    public function test_the_handler_still_comments_and_attaches(): void
    {
        $this->actingAs($this->admin);
        TicketService::assign($this->ticket, $this->technician->id, $this->admin->id, null, $this->ticket->fresh()->updated_at->utc()->format('Y-m-d\TH:i:s.v\Z'));

        $this->actingAs($this->technician)->get(route('admin.tickets.show', $this->ticket))->assertOk();

        $this->post(route('admin.tickets.comments.store', $this->ticket), ['body' => 'On my way', 'is_internal' => false])->assertRedirect();
        $this->post(route('admin.tickets.attachments.store', $this->ticket), ['file' => UploadedFile::fake()->create('proof.pdf', 10, 'application/pdf')])->assertRedirect();

        $this->assertSame(1, $this->ticket->comments()->count());
        $this->assertCount(1, $this->ticket->fresh()->getMedia('attachments'));
    }

    private function makeOpenTicket(): Ticket
    {
        $category = TicketCategory::create([
            'company_id' => $this->admin->company_id,
            'name' => 'Support',
            'code' => 'SUP-'.uniqid(),
            'default_sla_hours' => 24,
            'default_priority' => 'medium',
            'is_active' => true,
        ]);

        return TicketService::create([
            'title' => 'Downlink issue',
            'description' => 'Customer offline',
            'source' => 'internal',
            'category_id' => $category->id,
            'priority' => 'medium',
        ], $this->admin->id);
    }
}
