<?php

declare(strict_types=1);

namespace Tests\Feature\Ticketing;

use App\Models\Core\Company;
use App\Models\User;
use App\Services\Core\CompanyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Modules\Ticketing\Models\Ticket;
use Modules\Ticketing\Models\TicketCategory;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;
use Tests\Traits\CreatesCompanyUser;
use Tests\Traits\WithoutAppOnlyRedirect;

/**
 * F6-U11: "Tugaskan ke saya". Whoever may assign, and may be given a ticket to work, takes an unassigned ticket in one click.
 * A ticket somebody else took first is a 409 (never a quiet reassignment); the rest is a 403 or a 404.
 */
class TicketAssignSelfTest extends TestCase
{
    use CreatesCompanyUser;
    use RefreshDatabase;
    use WithoutAppOnlyRedirect;

    private User $admin;

    private User $dispatcher;

    private TicketCategory $category;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->admin = $this->createCompanyUser();
        $this->dispatcher = $this->member('noc');
        $this->category = TicketCategory::query()->create([
            'company_id' => $this->admin->company_id, 'name' => 'Gangguan', 'code' => 'CAT-'.Str::random(6),
            'default_sla_hours' => 24, 'default_priority' => 'medium', 'is_active' => true,
        ]);
    }

    public function test_a_dispatcher_takes_an_unassigned_ticket(): void
    {
        $ticket = $this->ticket();

        $this->actingAs($this->dispatcher)
            ->post(route('admin.tickets.assign-self', $ticket))
            ->assertRedirect()
            ->assertSessionHas('success', "Tiket {$ticket->code} ditugaskan ke Anda.");

        $this->assertSame($this->dispatcher->id, $ticket->fresh()->assigned_to);
        $this->assertSame('assigned', $ticket->fresh()->status);
        $audit = Activity::query()->where('log_name', 'ticket')->where('event', 'assigned')->sole();
        $this->assertSame($this->dispatcher->id, $audit->properties['handler_id']);
        $this->assertSame($this->dispatcher->id, $audit->properties['assigned_by']);
    }

    public function test_the_page_offers_it_on_an_unassigned_ticket_to_someone_who_may_be_given_the_work(): void
    {
        $open = $this->ticket();
        $taken = $this->ticket(['status' => 'assigned', 'assigned_to' => $this->dispatcher->id]);
        $assignOnly = $this->member(null, ['app.web', 'ticket.view', 'ticket.assign']);
        $offered = fn (User $user, Ticket $ticket): bool => $this->actingAs($user)->get(route('admin.tickets.show', $ticket))
            ->assertOk()->viewData('page')['props']['ticket']['data']['can']['assign_self'];

        $this->assertTrue($offered($this->dispatcher, $open));
        $this->assertTrue($offered($this->admin, $open));
        $this->assertFalse($offered($this->dispatcher, $taken), 'a ticket that has a handler is reassigned in the dialog');
        $this->assertFalse($offered($assignOnly, $open), 'they may assign, but nothing lets them work a ticket');
    }

    public function test_a_company_that_forbids_assigning_yourself_neither_offers_nor_allows_it(): void
    {
        $ticket = $this->ticket();
        $company = Company::query()->findOrFail($this->admin->company_id);
        $company->forceFill(['settings' => [...($company->settings ?? []), 'forbid_self_assignment' => true]])->save();
        CompanyService::resetCache();

        $this->actingAs($this->dispatcher)->post(route('admin.tickets.assign-self', $ticket))->assertForbidden();
        $this->assertNull($ticket->fresh()->assigned_to);
        $this->assertFalse($this->get(route('admin.tickets.show', $ticket))->assertOk()->viewData('page')['props']['ticket']['data']['can']['assign_self']);
    }

    public function test_a_ticket_somebody_took_first_is_a_409_and_stays_theirs(): void
    {
        $other = $this->member('technician');
        $ticket = $this->ticket(['status' => 'assigned', 'assigned_to' => $other->id]);

        $this->actingAs($this->dispatcher)->post(route('admin.tickets.assign-self', $ticket))->assertStatus(409);
        $this->post(route('admin.tickets.assign-self', $ticket), [], ['X-Inertia' => 'true'])
            ->assertRedirect()
            ->assertSessionHasErrors(['conflict' => 'Konflik penugasan: tiket ini sudah ditugaskan oleh pengguna lain.']);

        $this->assertSame($other->id, $ticket->fresh()->assigned_to);
    }

    public function test_without_the_permission_or_the_standing_of_a_handler_it_is_a_403(): void
    {
        $ticket = $this->ticket();
        $assignOnly = $this->member(null, ['app.web', 'ticket.view', 'ticket.assign']);
        $reader = $this->member('staff');

        foreach ([$assignOnly, $reader] as $user) {
            $this->actingAs($user)->post(route('admin.tickets.assign-self', $ticket))->assertForbidden();
        }

        $this->assertNull($ticket->fresh()->assigned_to);
    }

    public function test_a_ticket_of_another_company_is_not_found(): void
    {
        $ticket = $this->ticket();
        $foreign = $this->createCompanyUser();

        $this->actingAs($foreign)->post(route('admin.tickets.assign-self', $ticket))->assertNotFound();
        $this->assertNull($ticket->fresh()->assigned_to);
    }

    /**
     * @param  list<string>|null  $permissions  a role of exactly these, when no template role is named
     */
    private function member(?string $role, ?array $permissions = null): User
    {
        $user = User::factory()->create(['company_id' => $this->admin->company_id, 'email_verified_at' => now(), 'is_active' => true]);

        return $role === null ? $this->grantPermissions($user, $permissions ?? []) : $this->grantRole($user, $role);
    }

    /** @param  array<string, mixed>  $overrides */
    private function ticket(array $overrides = []): Ticket
    {
        return Ticket::query()->create($overrides + [
            'company_id' => $this->admin->company_id, 'code' => 'TKT-'.Str::upper(Str::random(8)), 'title' => 'Internet mati', 'source' => 'customer',
            'category_id' => $this->category->id, 'status' => 'open', 'priority' => 'medium', 'created_by' => $this->admin->id, 'sla_deadline' => now()->addDay(),
        ]);
    }
}
