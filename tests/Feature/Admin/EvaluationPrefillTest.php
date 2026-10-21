<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\Core\Company;
use App\Models\User;
use App\Support\PublicId;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Ticketing\Models\Ticket;
use Modules\Ticketing\Models\TicketCategory;
use Tests\TestCase;
use Tests\Traits\AsksInertiaForProps;
use Tests\Traits\BuildsCompanyGroup;

/**
 * F6-U15: "Evaluasi teknisi" on a closed ticket opens the form with the ticket and its handler chosen and the score left
 * for the evaluator. Anything the ticket page would not offer is the plain form, never an error.
 */
class EvaluationPrefillTest extends TestCase
{
    use AsksInertiaForProps;
    use BuildsCompanyGroup;
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildGroup();
        $this->admin = $this->groupUser($this->head, 'admin');
    }

    public function test_closed_ticket_prefills_technician_and_ticket(): void
    {
        $technician = $this->employee('Teknisi Sendiri');
        $ticket = $this->ticket(['assigned_to' => $technician->id]);

        $this->actingAs($this->admin)
            ->get(route('admin.evaluations.create', ['ticket' => $ticket->public_id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Evaluations/Create')
                ->where('prefill', [
                    'employee' => ['value' => $technician->public_id, 'label' => 'Teknisi Sendiri', 'description' => $technician->email],
                    'reference_type' => 'Ticket',
                    'reference' => ['value' => $ticket->public_id, 'label' => 'TKT-EVAL', 'description' => 'Internet mati'],
                    'customer_rating' => null,
                ]));
    }

    public function test_open_foreign_or_unassigned_ticket_gives_no_prefill(): void
    {
        $technician = $this->employee('Teknisi Sendiri');
        $foreignTechnician = $this->employee('Teknisi Asing', $this->outsider);
        $cases = [
            'still being worked' => ['ticket' => $this->ticket(['assigned_to' => $technician->id, 'status' => 'on_progress', 'closed_at' => null], 'TKT-WORKING')->public_id],
            'resolved but not closed' => ['ticket' => $this->ticket(['assigned_to' => $technician->id, 'status' => 'resolved', 'closed_at' => null], 'TKT-RESOLVED')->public_id],
            'closed without a handler' => ['ticket' => $this->ticket(['assigned_to' => null], 'TKT-ORPHAN')->public_id],
            'of another company' => ['ticket' => $this->ticket(['assigned_to' => $foreignTechnician->id], 'TKT-FOREIGN', $this->outsider)->public_id],
            'unknown' => ['ticket' => PublicId::new()],
            'not an id' => ['ticket' => 'TKT-WORKING'],
            'not a text' => ['ticket' => ['TKT-WORKING']],
            'none asked for' => [],
        ];

        foreach ($cases as $why => $query) {
            $props = $this->actingAs($this->admin)->get(route('admin.evaluations.create', $query))->assertOk()->viewData('page')['props'];

            $this->assertArrayHasKey('prefill', $props, $why);
            $this->assertNull($props['prefill'], $why);
        }
    }

    public function test_create_has_no_default_score(): void
    {
        $ticket = $this->ticket(['assigned_to' => $this->employee('Teknisi Sendiri')->id]);

        $props = $this->actingAs($this->admin)
            ->get(route('admin.evaluations.create', ['ticket' => $ticket->public_id]))
            ->assertOk()->viewData('page')['props'];

        $keys = array_keys($props['prefill']);
        sort($keys);
        $this->assertSame(['customer_rating', 'employee', 'reference', 'reference_type'], $keys, 'the evaluator gives the score: the page offers none');
        $this->assertArrayNotHasKey('score', $props);
    }

    public function test_the_rating_the_customer_gave_the_ticket_comes_with_the_prefill(): void
    {
        $ticket = $this->ticket(['assigned_to' => $this->employee('Teknisi Sendiri')->id, 'customer_rating' => 4, 'customer_rating_comment' => 'Cepat', 'customer_rated_at' => now()]);

        $this->actingAs($this->admin)
            ->get(route('admin.evaluations.create', ['ticket' => $ticket->public_id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('prefill.customer_rating', 4));
    }

    public function test_a_handler_the_form_cannot_rate_leaves_the_employee_to_be_picked(): void
    {
        $manager = $this->employee('Manajer Sendiri', $this->head, 'manager');
        $ticket = $this->ticket(['assigned_to' => $manager->id]);

        $this->actingAs($this->admin)
            ->get(route('admin.evaluations.create', ['ticket' => $ticket->public_id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('prefill.employee', null)
                ->where('prefill.reference.value', $ticket->public_id));
    }

    public function test_option_searches_do_not_resolve_the_prefill(): void
    {
        $ticket = $this->ticket(['assigned_to' => $this->employee('Teknisi Sendiri')->id]);

        $this->actingAs($this->admin)
            ->get(route('admin.evaluations.create', ['ticket' => $ticket->public_id, 'employee_search' => 'Teknisi']), $this->partialReload('Admin/Evaluations/Create', 'employeeOptions'))
            ->assertOk()
            ->assertJsonMissingPath('props.prefill')
            ->assertJsonPath('props.employeeOptions.0.label', 'Teknisi Sendiri');
    }

    private function employee(string $name, ?Company $home = null, string $role = 'technician'): User
    {
        $user = $this->groupUser($home ?? $this->head, $role);
        $user->forceFill(['name' => $name])->save();

        return $user;
    }

    /** @param  array<string, mixed>  $attributes */
    private function ticket(array $attributes, string $code = 'TKT-EVAL', ?Company $company = null): Ticket
    {
        $company ??= $this->head;
        $category = TicketCategory::withoutCompany()->firstOrCreate(
            ['company_id' => $company->id, 'name' => 'Gangguan'],
            ['code' => 'CAT-'.Str::random(6), 'default_sla_hours' => 24, 'default_priority' => 'medium', 'is_active' => true],
        );

        return Ticket::withoutCompany()->create($attributes + [
            'company_id' => $company->id, 'code' => $code, 'title' => 'Internet mati', 'source' => 'customer', 'category_id' => $category->id,
            'status' => 'closed', 'closed_at' => now(), 'priority' => 'medium', 'created_by' => $this->admin->id, 'sla_deadline' => now()->addDay(),
        ]);
    }
}
