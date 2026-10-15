<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\SPK\Database\Factories\WorkOrderFactory;
use Modules\Ticketing\Models\Ticket;
use Tests\TestCase;
use Tests\Traits\CreatesCompanyUser;

/**
 * The dead "Kritis" priority: a filter offered `critical`, which no ticket or SPK can hold, so choosing it listed nothing.
 * Tickets and SPK share one vocabulary, the four values of their `priority` columns; the pages build their filter and form
 * options from the same four (resources/js/lib/statuses PRIORITY, pinned by statuses.selfcheck.mjs).
 */
class PriorityVocabularyTest extends TestCase
{
    use CreatesCompanyUser;
    use RefreshDatabase;

    private const PRIORITIES = ['low', 'medium', 'high', 'urgent'];

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->createCompanyUser();
        $this->actingAs($this->admin);
    }

    public function test_ticket_and_spk_forms_accept_the_four_priorities_and_refuse_critical(): void
    {
        foreach (['admin.tickets.store', 'admin.spk.store'] as $route) {
            foreach (self::PRIORITIES as $priority) {
                $this->post(route($route), ['priority' => $priority])->assertSessionDoesntHaveErrors('priority');
            }

            $this->post(route($route), ['priority' => 'critical'])->assertSessionHasErrors(['priority' => 'prioritas yang dipilih tidak valid.']);
        }
    }

    public function test_the_ticket_and_dispatch_filters_find_each_priority_and_critical_finds_nothing(): void
    {
        $companyId = (int) $this->admin->company_id;
        foreach (self::PRIORITIES as $priority) {
            Ticket::factory()->create(['company_id' => $companyId, 'priority' => $priority]);
            WorkOrderFactory::new()->create(['company_id' => $companyId, 'priority' => $priority]);
        }

        foreach (self::PRIORITIES as $priority) {
            $this->get(route('admin.tickets.index', ['priority' => $priority]))
                ->assertInertia(fn (Assert $page) => $page
                    ->has('tickets.data', 1)
                    ->where('tickets.data.0.priority', $priority));

            // The dispatch queue lists the ticket and the SPK of that priority.
            $this->get(route('admin.dispatch.index', ['priority' => $priority]))
                ->assertInertia(fn (Assert $page) => $page
                    ->has('items.data', 2)
                    ->where('items.data.0.priority', $priority)
                    ->where('items.data.1.priority', $priority));
        }

        $this->get(route('admin.tickets.index', ['priority' => 'critical']))
            ->assertInertia(fn (Assert $page) => $page->has('tickets.data', 0));
        $this->get(route('admin.dispatch.index', ['priority' => 'critical']))
            ->assertInertia(fn (Assert $page) => $page->has('items.data', 0));
    }

    public function test_no_ticket_or_spk_row_can_hold_critical(): void
    {
        $ticket = Ticket::factory()->create(['company_id' => $this->admin->company_id]);
        $workOrder = WorkOrderFactory::new()->create(['company_id' => $this->admin->company_id]);

        $this->assertThrows(fn () => DB::table('tickets')->where('id', $ticket->id)->update(['priority' => 'critical']), QueryException::class);
        $this->assertThrows(fn () => DB::table('work_orders')->where('id', $workOrder->id)->update(['priority' => 'critical']), QueryException::class);
    }
}
