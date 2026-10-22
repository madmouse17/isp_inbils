<?php

namespace Tests\Feature\Admin;

use App\Models\Core\Company;
use App\Models\Core\Customer;
use App\Models\Core\Location;
use App\Models\User;
use App\Queries\Admin\ApprovalInboxQuery;
use App\Queries\Admin\DispatchQueueQuery;
use App\Queries\Admin\MyWorkQuery;
use App\Services\Core\CompanyService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Modules\Billing\Database\Factories\PaymentAttemptFactory;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\PaymentAttempt;
use Modules\Billing\Services\BillingService;
use Modules\Inventory\Services\InventoryRequestService;
use Modules\Inventory\Services\MaterialReturnService;
use Modules\Ticketing\Models\Ticket;
use Modules\Ticketing\Models\TicketComment;
use Tests\TestCase;
use Tests\Traits\BuildsDashboardFixtures;

/**
 * Exact counts, definitions and boundaries behind every dashboard card. Each case seeds a
 * second company with the same shapes: nothing of it may ever be counted.
 */
class DashboardExceptionCountsTest extends TestCase
{
    use BuildsDashboardFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedDashboardRoles();
        Carbon::setTestNow(Carbon::parse('2026-10-10 12:00:00'));
    }

    public function test_dispatch_unassigned_equals_the_dispatch_summary_and_ignores_other_companies(): void
    {
        $company = $this->makeCompany();
        $other = $this->makeCompany();
        $manager = $this->userWithRole($company, 'manager');
        $assignee = $this->fixtureCreator($company);

        $this->ticket($company, ['status' => 'open']);
        $this->ticket($company, ['status' => 'open']);
        $this->ticket($company, ['status' => 'assigned', 'assigned_to' => $assignee->id]);
        $this->ticket($company, ['status' => 'on_progress', 'assigned_to' => $assignee->id]);
        $this->workOrder($company, ['status' => 'draft']);
        $this->workOrder($company, ['status' => 'generated']);
        $this->workOrder($company, ['status' => 'rejected']);
        $this->workOrder($company, ['status' => 'assigned', 'assigned_to' => $assignee->id]);
        $this->workOrder($company, ['status' => 'in_progress']);
        foreach (range(1, 3) as $ignored) {
            $this->ticket($other, ['status' => 'open']);
            $this->workOrder($other, ['status' => 'draft']);
        }

        $section = $this->section($manager, 'dispatch_unassigned');

        $this->assertSame(5, $section['count']);
        $this->assertSame(['tickets' => 2, 'spk' => 3], $section['breakdown']);
        $this->assertSame('warning', $section['severity']);
        $this->assertSame('operations', $section['group']);
        $this->assertSame(DispatchQueueQuery::summary($this->requestFor($manager))['unassigned'], $section['count']);
        $this->assertSame(route('admin.dispatch.index', ['status' => 'unassigned']), $section['href']);
    }

    public function test_approvals_waiting_excludes_maker_checker_rows_other_statuses_and_other_companies(): void
    {
        $company = $this->makeCompany();
        $other = $this->makeCompany();
        $manager = $this->userWithRole($company, 'manager');
        $creator = $this->fixtureCreator($company);

        $this->workOrder($company, ['status' => 'waiting_review', 'created_by' => $creator->id]);
        $this->workOrder($company, ['status' => 'waiting_review', 'created_by' => $creator->id]);
        $this->workOrder($company, ['status' => 'waiting_review', 'created_by' => $manager->id]);
        $this->workOrder($company, ['status' => 'waiting_review', 'created_by' => $creator->id, 'assigned_to' => $manager->id]);
        $this->workOrder($company, ['status' => 'in_progress', 'created_by' => $creator->id]);
        $this->inventoryRequest($company, $creator, 'submitted');
        $this->inventoryRequest($company, $manager, 'submitted');
        $this->inventoryRequest($company, $creator, 'draft');
        $otherCreator = $this->fixtureCreator($other);
        $this->workOrder($other, ['status' => 'waiting_review', 'created_by' => $otherCreator->id]);
        $this->inventoryRequest($other, $otherCreator, 'submitted');

        $section = $this->section($manager, 'approvals');

        $this->assertSame(3, $section['count']);
        $this->assertSame('warning', $section['severity']);
        $this->assertSame('approvals', $section['group']);
        $this->assertSame(ApprovalInboxQuery::summary($this->requestFor($manager))['waiting_for_me'], $section['count']);
        $this->assertSame(route('admin.approvals.index'), $section['href']);
    }

    public function test_approvals_only_count_the_domains_the_actor_may_approve(): void
    {
        $company = $this->makeCompany();
        $inventoryApprover = $this->userWithPermissions($company, [
            'dashboard.view', 'inventory.request.approve', 'inventory.request.reject',
        ]);
        $creator = $this->fixtureCreator($company);
        $this->workOrder($company, ['status' => 'waiting_review', 'created_by' => $creator->id]);
        $this->inventoryRequest($company, $creator, 'submitted');

        $this->assertSame(1, $this->section($inventoryApprover, 'approvals')['count']);
    }

    public function test_seeded_operator_has_no_approvals_card_even_when_requests_wait(): void
    {
        $company = $this->makeCompany();
        $staff = $this->userWithRole($company, 'staff');
        $creator = $this->fixtureCreator($company);
        $this->workOrder($company, ['status' => 'waiting_review', 'created_by' => $creator->id]);
        $this->inventoryRequest($company, $creator, 'submitted');

        $keys = collect($this->sectionsOf($this->actingAs($staff)->get(route('admin.dashboard'))->assertOk()))->pluck('key');

        $this->assertNotContains('approvals', $keys->all());
    }

    public function test_the_approval_count_cannot_be_narrowed_by_request_input(): void
    {
        $company = $this->makeCompany();
        $manager = $this->userWithRole($company, 'manager');
        $creator = $this->fixtureCreator($company);
        $this->workOrder($company, ['status' => 'waiting_review', 'created_by' => $creator->id]);
        $this->workOrder($company, ['status' => 'waiting_review', 'created_by' => $creator->id]);

        foreach (['spk', 'inventory', 'bogus'] as $domain) {
            $response = $this->actingAs($manager)->get(route('admin.dashboard', ['domain' => $domain]))->assertOk();

            $this->assertSame(2, $this->sectionOf($response, 'approvals')['count'], $domain);
        }
    }

    public function test_my_work_equals_the_my_work_summary(): void
    {
        $company = $this->makeCompany();
        $other = $this->makeCompany();
        $technician = $this->userWithRole($company, 'technician');
        $this->activeEmployeeProfile($technician);
        $colleague = $this->fixtureCreator($company);

        $this->ticket($company, ['status' => 'assigned', 'assigned_to' => $technician->id]);
        $this->ticket($company, ['status' => 'on_progress', 'assigned_to' => $technician->id]);
        $this->ticket($company, ['status' => 'resolved', 'assigned_to' => $technician->id]);
        $this->ticket($company, ['status' => 'assigned', 'assigned_to' => $colleague->id]);
        $this->workOrder($company, ['status' => 'assigned', 'assigned_to' => $technician->id]);
        $this->workOrder($company, ['status' => 'in_progress', 'assigned_to' => $technician->id]);
        $this->workOrder($company, ['status' => 'in_progress', 'assigned_to' => $colleague->id]);
        $this->workOrder($company, ['status' => 'waiting_review', 'assigned_to' => $technician->id]);
        $this->ticket($other, ['status' => 'assigned', 'assigned_to' => $technician->id]);
        $this->workOrder($other, ['status' => 'assigned', 'assigned_to' => $technician->id]);

        $section = $this->section($technician, 'my_work');
        $summary = MyWorkQuery::summary($this->requestFor($technician));

        $this->assertSame(4, $section['count']);
        $this->assertSame($summary['total'], $section['count']);
        $this->assertSame(Arr::only($summary, ['assigned', 'in_progress', 'waiting_action']), $section['breakdown']);
        $this->assertSame('info', $section['severity']);
        $this->assertSame('operations', $section['group']);
        $this->assertSame(route('admin.my-work.index'), $section['href']);
    }

    public function test_sla_breached_applies_the_ticket_list_definition_at_its_boundaries(): void
    {
        $company = $this->makeCompany();
        $other = $this->makeCompany();
        $manager = $this->userWithRole($company, 'manager');
        $owner = $this->fixtureCreator($company);

        $threeHours = $this->ticket($company, ['status' => 'open', 'sla_deadline' => now()->subHours(3)]);
        $twoHours = $this->ticket($company, ['status' => 'assigned', 'assigned_to' => $owner->id, 'sla_deadline' => now()->subHours(2)]);
        $oneHour = $this->ticket($company, ['status' => 'on_progress', 'assigned_to' => $owner->id, 'sla_deadline' => now()->subHour()]);
        $justBreached = $this->ticket($company, ['status' => 'open', 'sla_deadline' => now()->subSecond()]);
        $this->ticket($company, ['status' => 'open', 'sla_deadline' => now()]);
        $this->ticket($company, ['status' => 'open', 'sla_deadline' => now()->addSecond()]);
        $this->ticket($company, ['status' => 'open', 'sla_deadline' => null]);
        $this->ticket($company, ['status' => 'resolved', 'sla_deadline' => now()->subDay()]);
        $this->ticket($company, ['status' => 'closed', 'sla_deadline' => now()->subDay()]);
        $this->ticket($company, ['status' => 'open', 'sla_deadline' => now()->subDay()])->delete();
        $this->ticket($other, ['status' => 'open', 'sla_deadline' => now()->subDay()]);
        $this->ticket($other, ['status' => 'assigned', 'sla_deadline' => now()->subDay()]);

        $section = $this->section($manager, 'sla_breached');

        $this->assertSame(4, $section['count']);
        $this->assertSame('danger', $section['severity']);
        $this->assertSame('operations', $section['group']);
        $this->assertSame(route('admin.tickets.index', ['sla_breached' => 1]), $section['href']);
        $this->assertSame(
            [$threeHours->public_id, $twoHours->public_id, $oneHour->public_id, $justBreached->public_id],
            array_column($section['items'], 'id'),
            'oldest deadline first',
        );
        $this->assertSame(
            ['id', 'code', 'title', 'priority', 'status', 'sla_deadline', 'href'],
            array_keys($section['items'][0]),
        );
        $this->assertSame($threeHours->code, $section['items'][0]['code']);
        $this->assertSame('open', $section['items'][0]['status']);
        $this->assertSame($threeHours->fresh()->sla_deadline->toISOString(), $section['items'][0]['sla_deadline']);
        $this->assertSame(route('admin.tickets.show', $threeHours), $section['items'][0]['href']);
    }

    public function test_sla_breached_items_are_the_five_oldest_while_the_count_stays_complete(): void
    {
        $company = $this->makeCompany();
        $manager = $this->userWithRole($company, 'manager');

        $ids = [];
        foreach (range(7, 1) as $hoursAgo) {
            $ids[$hoursAgo] = $this->ticket($company, ['sla_deadline' => now()->subHours($hoursAgo)])->public_id;
        }

        $section = $this->section($manager, 'sla_breached');

        $this->assertSame(7, $section['count']);
        $this->assertSame([$ids[7], $ids[6], $ids[5], $ids[4], $ids[3]], array_column($section['items'], 'id'));
    }

    public function test_a_handler_without_assign_counts_only_their_own_breached_tickets_like_the_ticket_list(): void
    {
        $company = $this->makeCompany();
        $other = $this->makeCompany();
        $technician = $this->userWithRole($company, 'technician');
        $colleague = $this->fixtureCreator($company);

        $mine = $this->ticket($company, ['status' => 'assigned', 'assigned_to' => $technician->id, 'sla_deadline' => now()->subHours(2)]);
        $this->ticket($company, ['status' => 'on_progress', 'assigned_to' => $technician->id, 'sla_deadline' => now()->subHour()]);
        $this->ticket($company, ['status' => 'assigned', 'assigned_to' => $colleague->id, 'sla_deadline' => now()->subHours(5)]);
        $this->ticket($company, ['status' => 'open', 'sla_deadline' => now()->subHours(6)]);
        $this->ticket($other, ['status' => 'assigned', 'assigned_to' => $technician->id, 'sla_deadline' => now()->subHours(7)]);

        $section = $this->section($technician, 'sla_breached');

        $this->assertSame(2, $section['count']);
        $this->assertSame($mine->public_id, $section['items'][0]['id']);
        $this->assertSame(
            2,
            $this->actingAs($technician)->get($section['href'])->inertiaProps('tickets.meta.total'),
            'the technician ticket list shows the same two rows',
        );

        $inactive = $this->userWithRole($company, 'technician', active: false);
        $this->ticket($company, ['status' => 'assigned', 'assigned_to' => $inactive->id, 'sla_deadline' => now()->subHour()]);
        $inactiveSection = $this->section($inactive, 'sla_breached');
        $this->assertSame(0, $inactiveSection['count']);
        $this->assertSame([], $inactiveSection['items']);
        $this->assertSame(0, $this->actingAs($inactive)->get($inactiveSection['href'])->inertiaProps('tickets.meta.total'));
    }

    public function test_an_sla_reader_without_handler_permissions_counts_every_company_ticket_like_the_ticket_list(): void
    {
        $company = $this->makeCompany();
        $other = $this->makeCompany();
        $staff = $this->userWithRole($company, 'staff');
        $colleague = $this->fixtureCreator($company);

        $this->ticket($company, ['status' => 'assigned', 'assigned_to' => $colleague->id, 'sla_deadline' => now()->subHours(2)]);
        $this->ticket($company, ['status' => 'open', 'sla_deadline' => now()->subHour()]);
        $this->ticket($other, ['status' => 'open', 'sla_deadline' => now()->subHour()]);

        $section = $this->section($staff, 'sla_breached');

        $this->assertSame(2, $section['count']);
        $this->assertSame(2, $this->actingAs($staff)->get($section['href'])->inertiaProps('tickets.meta.total'));
    }

    public function test_due_soon_counts_unanswered_response_and_resolution_targets_inside_half_an_hour_like_the_ticket_list(): void
    {
        $company = $this->makeCompany();
        $other = $this->makeCompany();
        $manager = $this->userWithRole($company, 'manager');
        $answered = ['first_response_at' => now()->subMinutes(5)];

        $this->ticket($company, ['response_deadline' => now()->addMinutes(20)]);
        $this->ticket($company, ['status' => 'assigned', 'response_deadline' => now()->addMinutes(30)]);
        $this->ticket($company, ['status' => 'on_progress', 'sla_deadline' => now()->addMinutes(25), ...$answered]);
        $this->ticket($company, ['response_deadline' => now()->addMinutes(20), ...$answered]);
        $this->ticket($company, ['response_deadline' => now()->addMinutes(31)]);
        $this->ticket($company, ['response_deadline' => now()->subSecond()]);
        $this->ticket($company, ['status' => 'resolved', 'response_deadline' => now()->addMinutes(10), 'sla_deadline' => now()->addMinutes(10)]);
        $this->ticket($company, ['status' => 'closed', 'sla_deadline' => now()->addMinutes(10)]);
        $this->ticket($company, ['response_deadline' => now()->addMinutes(10)])->delete();
        $this->ticket($other, ['response_deadline' => now()->addMinutes(10)]);

        $section = $this->section($manager, 'sla_due_soon');

        $this->assertSame(3, $section['count'], 'an unanswered response and a resolution inside the window count; an answered, late, far, finished, deleted or foreign one does not');
        $this->assertSame('Hampir terlampaui (≤ 30 menit)', $section['title']);
        $this->assertSame('warning', $section['severity']);
        $this->assertSame('operations', $section['group']);
        $this->assertSame(route('admin.tickets.index', ['due_soon' => 1]), $section['href']);
        $this->assertSame($section['count'], $this->actingAs($manager)->get($section['href'])->inertiaProps('tickets.meta.total'));
    }

    public function test_customer_replied_counts_tickets_whose_newest_public_comment_is_the_customers_like_the_ticket_list(): void
    {
        $company = $this->makeCompany();
        $other = $this->makeCompany();
        $manager = $this->userWithRole($company, 'manager');
        [$login, $customer] = $this->portalCustomer($company);
        [$strangerLogin] = $this->portalCustomer($company);
        $mine = ['customer_id' => $customer->id];

        $this->comment($this->ticket($company, $mine), $login);
        $answered = $this->ticket($company, $mine);
        $this->comment($answered, $login);
        $this->comment($answered, $manager);
        $asksAgain = $this->ticket($company, $mine);
        $this->comment($asksAgain, $manager);
        $this->comment($asksAgain, $login);
        $noted = $this->ticket($company, $mine);
        $this->comment($noted, $login);
        $this->comment($noted, $manager, internal: true);
        $this->comment($this->ticket($company, [...$mine, 'status' => 'resolved']), $login);
        $this->comment($this->ticket($company, $mine), $strangerLogin);
        [$foreignLogin, $foreignCustomer] = $this->portalCustomer($other);
        $this->comment($this->ticket($other, ['customer_id' => $foreignCustomer->id]), $foreignLogin);

        $section = $this->section($manager, 'customer_replied');

        $this->assertSame(3, $section['count'], 'unanswered, asked again, and answered by a note only; a staff answer, a finished ticket, a stranger and another company do not count');
        $this->assertSame('Pelanggan membalas', $section['title']);
        $this->assertSame('info', $section['severity']);
        $this->assertSame('operations', $section['group']);
        $this->assertSame(route('admin.tickets.index', ['awaiting_reply' => 1]), $section['href']);
        $this->assertSame($section['count'], $this->actingAs($manager)->get($section['href'])->inertiaProps('tickets.meta.total'));
    }

    public function test_the_due_soon_and_customer_replied_cards_keep_the_handler_scope_of_the_ticket_list(): void
    {
        $company = $this->makeCompany();
        $technician = $this->userWithRole($company, 'technician');
        $colleague = $this->fixtureCreator($company);
        [$login, $customer] = $this->portalCustomer($company);

        foreach ([['assigned', $technician], ['assigned', $colleague], ['open', null]] as [$status, $handler]) {
            $ticket = $this->ticket($company, [
                'status' => $status,
                'assigned_to' => $handler?->id,
                'customer_id' => $customer->id,
                'response_deadline' => now()->addMinutes(10),
            ]);
            $this->comment($ticket, $login);
        }

        foreach (['sla_due_soon', 'customer_replied'] as $key) {
            $section = $this->section($technician, $key);

            $this->assertSame(1, $section['count'], $key);
            $this->assertSame(1, $this->actingAs($technician)->get($section['href'])->inertiaProps('tickets.meta.total'), $key);
        }

        $inactive = $this->userWithRole($company, 'technician', active: false);
        $this->ticket($company, ['status' => 'assigned', 'assigned_to' => $inactive->id, 'response_deadline' => now()->addMinutes(10)]);

        foreach (['sla_due_soon', 'customer_replied'] as $key) {
            $section = $this->section($inactive, $key);

            $this->assertSame(0, $section['count'], $key);
            $this->assertSame(0, $this->actingAs($inactive)->get($section['href'])->inertiaProps('tickets.meta.total'), $key);
        }
    }

    public function test_overdue_invoices_are_computed_from_due_date_and_open_balance_not_from_the_status_flip(): void
    {
        $company = $this->makeCompany();
        $other = $this->makeCompany();
        $manager = $this->userWithRole($company, 'manager');

        $this->invoice($company, ['status' => 'sent', 'due_date' => '2026-10-09', 'total' => '1000000.00', 'paid_amount' => '0.00']);
        $this->invoice($company, ['status' => 'partial', 'due_date' => '2026-10-01', 'total' => '500000.00', 'paid_amount' => '200000.00']);
        $this->invoice($company, ['status' => 'overdue', 'due_date' => '2026-09-01', 'total' => '250000.50', 'paid_amount' => '0.00']);
        $this->invoice($company, ['status' => 'sent', 'due_date' => '2026-10-10']);
        $this->invoice($company, ['status' => 'sent', 'due_date' => '2026-10-11']);
        $this->invoice($company, ['status' => 'draft', 'due_date' => '2026-10-09']);
        $this->invoice($company, ['status' => 'cancelled', 'due_date' => '2026-10-09']);
        $this->invoice($company, ['status' => 'paid', 'due_date' => '2026-10-09', 'paid_amount' => '1000000.00']);
        $this->invoice($company, ['status' => 'sent', 'due_date' => '2026-10-09', 'paid_amount' => '1000000.00']);
        $this->invoice($company, ['status' => 'sent', 'due_date' => '2026-10-09'])->delete();
        $this->invoice($other, ['status' => 'sent', 'due_date' => '2026-10-09']);
        $this->invoice($other, ['status' => 'overdue', 'due_date' => '2026-09-01']);

        $section = $this->section($manager, 'overdue_invoices');

        $this->assertSame(3, $section['count']);
        $this->assertSame('1550000.50', $section['outstanding']);
        $this->assertSame('danger', $section['severity']);
        $this->assertSame('finance', $section['group']);
        $this->assertSame(route('admin.invoices.index', ['status' => 'overdue']), $section['href']);
    }

    public function test_overdue_invoice_count_equals_the_invoice_list_once_the_scheduler_has_flipped_the_status(): void
    {
        $company = $this->makeCompany();
        $manager = $this->userWithRole($company, 'manager');
        $this->invoice($company, ['status' => 'sent', 'due_date' => '2026-10-09']);
        $this->invoice($company, ['status' => 'partial', 'due_date' => '2026-10-02', 'paid_amount' => '1.00']);
        $this->invoice($company, ['status' => 'overdue', 'due_date' => '2026-09-02']);

        $section = $this->section($manager, 'overdue_invoices');
        $before = $this->actingAs($manager)->get($section['href'])->inertiaProps('invoices.meta.total');
        $this->assertSame(3, $section['count']);
        $this->assertSame(1, $before, 'until the scheduler runs the list only knows the flipped invoice');

        BillingService::checkOverdue($company->id);

        $this->assertSame(3, $this->actingAs($manager)->get($section['href'])->inertiaProps('invoices.meta.total'));
        $this->assertSame(3, $this->section($manager, 'overdue_invoices')['count']);
    }

    public function test_due_soon_invoice_card_counts_what_the_invoice_list_shows_for_due_within_three_days(): void
    {
        $company = $this->makeCompany();
        $other = $this->makeCompany();
        $manager = $this->userWithRole($company, 'manager');

        // Today is 2026-10-10: the window is 10 October through 13 October.
        $this->invoice($company, ['status' => 'sent', 'due_date' => '2026-10-10']);
        $this->invoice($company, ['status' => 'partial', 'due_date' => '2026-10-12', 'paid_amount' => '200000.00']);
        $this->invoice($company, ['status' => 'sent', 'due_date' => '2026-10-13']);
        $this->invoice($company, ['status' => 'sent', 'due_date' => '2026-10-14']);
        $this->invoice($company, ['status' => 'sent', 'due_date' => '2026-10-09']);
        $this->invoice($company, ['status' => 'overdue', 'due_date' => '2026-10-11']);
        $this->invoice($company, ['status' => 'draft', 'due_date' => '2026-10-11']);
        $this->invoice($company, ['status' => 'paid', 'due_date' => '2026-10-11', 'paid_amount' => '1000000.00']);
        $this->invoice($company, ['status' => 'sent', 'due_date' => '2026-10-11', 'paid_amount' => '1000000.00']);
        $this->invoice($company, ['status' => 'sent', 'due_date' => '2026-10-11'])->delete();
        $this->invoice($other, ['status' => 'sent', 'due_date' => '2026-10-11']);

        $section = $this->section($manager, 'invoices_due_soon');

        $this->assertSame(3, $section['count']);
        $this->assertSame('warning', $section['severity']);
        $this->assertSame('finance', $section['group']);
        $this->assertSame('Jatuh tempo ≤ 3 hari', $section['title']);
        $this->assertSame(route('admin.invoices.index', ['due_within' => 3]), $section['href']);
        $this->assertSame(3, $this->actingAs($manager)->get($section['href'])->inertiaProps('invoices.meta.total'));
    }

    public function test_draft_invoice_card_counts_the_drafts_the_invoice_list_shows_for_status_draft(): void
    {
        $company = $this->makeCompany();
        $other = $this->makeCompany();
        $manager = $this->userWithRole($company, 'manager');
        $this->invoice($company, ['status' => 'draft', 'total' => '250000.00']);
        $this->invoice($company, ['status' => 'draft', 'total' => '50000.50']);
        $this->invoice($company, ['status' => 'draft'])->delete();
        $this->invoice($company, ['status' => 'sent']);
        $this->invoice($company, ['status' => 'cancelled']);
        $this->invoice($other, ['status' => 'draft']);

        $section = $this->section($manager, 'draft_invoices');

        $this->assertSame(2, $section['count']);
        $this->assertSame('300000.50', $section['total']);
        $this->assertSame('warning', $section['severity']);
        $this->assertSame('finance', $section['group']);
        $this->assertSame(route('admin.invoices.index', ['status' => 'draft']), $section['href']);
        $this->assertSame($section['count'], $this->actingAs($manager)->get($section['href'])->inertiaProps('invoices.meta.total'));
    }

    public function test_online_payment_exceptions_count_the_open_exceptions_of_the_receivables_page_and_ignore_other_companies(): void
    {
        $company = $this->makeCompany();
        $other = $this->makeCompany();
        $manager = $this->userWithRole($company, 'manager');
        $attempt = fn (Company $owner, string $status, array $attributes = []): PaymentAttempt => PaymentAttemptFactory::new()
            ->forInvoice($this->invoice($owner))->status($status)->create($attributes);

        $attempt($company, PaymentAttempt::STATUS_EXCEPTION);
        $attempt($company, PaymentAttempt::STATUS_EXCEPTION, ['last_notification' => ['transaction_status' => 'settlement']]);
        $attempt($company, PaymentAttempt::STATUS_EXCEPTION, ['last_notification' => ['transaction_status' => 'refund']]);
        $attempt($company, PaymentAttempt::STATUS_EXCEPTION, ['last_notification' => ['transaction_status' => 'chargeback']]);
        $attempt($company, PaymentAttempt::STATUS_REFUNDED);
        $attempt($company, PaymentAttempt::STATUS_FAILED);
        $attempt($company, PaymentAttempt::STATUS_PENDING);
        $attempt($other, PaymentAttempt::STATUS_EXCEPTION);

        $section = $this->section($manager, 'online_payment_exceptions');

        $this->assertSame(2, $section['count'], 'money still to settle; Midtrans took back, refunded, failed, pending and foreign ones are not');
        $this->assertSame('Pembayaran online bermasalah', $section['title']);
        $this->assertSame('danger', $section['severity']);
        $this->assertSame('finance', $section['group']);
        $this->assertSame(route('admin.billing.receivables'), $section['href']);
        $this->assertSame($section['count'], $this->actingAs($manager)->get($section['href'])->inertiaProps('paymentExceptions.count'));
    }

    public function test_online_payment_pending_counts_each_invoice_whose_page_has_been_open_over_an_hour_like_the_invoice_list(): void
    {
        $company = $this->makeCompany();
        $other = $this->makeCompany();
        $manager = $this->userWithRole($company, 'manager');
        $page = fn (Company $owner, array $attributes = [], string $status = PaymentAttempt::STATUS_PENDING, ?Invoice $invoice = null): PaymentAttempt => PaymentAttemptFactory::new()
            ->forInvoice($invoice ?? $this->invoice($owner))->status($status)->create($attributes);

        $page($company, ['created_at' => now()->subHours(2)]);
        $twice = $this->invoice($company);
        $page($company, ['created_at' => now()->subHours(3)], invoice: $twice);
        $page($company, ['created_at' => now()->subHours(2)], invoice: $twice);
        $page($company, ['created_at' => now()->subMinutes(60)]);
        $page($company, ['created_at' => now()->subMinutes(59)]);
        $page($company, ['created_at' => now()->subHours(25), 'expires_at' => now()->subMinute()]);
        $page($company, ['created_at' => now()->subHours(2)], PaymentAttempt::STATUS_FAILED);
        $deleted = $this->invoice($company);
        $page($company, ['created_at' => now()->subHours(2)], invoice: $deleted);
        $deleted->delete();
        $page($other, ['created_at' => now()->subHours(2)]);

        $section = $this->section($manager, 'online_payment_pending');

        $this->assertSame(3, $section['count'], 'an invoice counts once, at exactly an hour; a recent, ran-out, failed, deleted or foreign page does not');
        $this->assertSame('Pembayaran online tertunda > 1 jam', $section['title']);
        $this->assertSame('warning', $section['severity']);
        $this->assertSame('finance', $section['group']);
        $this->assertSame(route('admin.invoices.index', ['online_pending' => 1]), $section['href']);
        $this->assertSame($section['count'], $this->actingAs($manager)->get($section['href'])->inertiaProps('invoices.meta.total'));
    }

    public function test_low_stock_counts_the_same_stock_rows_the_stocks_list_shows_for_low_stock(): void
    {
        $company = $this->makeCompany();
        $other = $this->makeCompany();
        $manager = $this->userWithRole($company, 'manager');

        $this->stockRow($company, '4.00', '5.00');
        $this->stockRow($company, '5.00', '5.00');
        $this->stockRow($company, '6.00', '5.00');
        $this->stockRow($company, '0.00', '0.00');
        $this->stockRow($company, '1.00', '0.00');
        $this->stockRow($company, '5.00', '5.00', ['is_active' => false]);
        $this->stockRow($company, '0.00', '5.00')->product()->withoutCompany()->firstOrFail()->delete();
        $twoSites = $this->stockRow($company, '1.00', '5.00');
        $second = Location::create([
            'company_id' => $company->id,
            'code' => 'LOC-DASH-2',
            'name' => 'Second site',
            'type' => 'site',
            'path' => 'Second',
            'is_active' => true,
        ]);
        $twoSites->replicate()->forceFill(['location_id' => $second->id])->save();
        $this->stockRow($other, '0.00', '5.00');
        $this->stockRow($other, '1.00', '9.00');

        $section = $this->section($manager, 'low_stock');

        $this->assertSame(7, $section['count']);
        $this->assertSame('warning', $section['severity']);
        $this->assertSame('warehouse', $section['group']);
        $this->assertSame(route('admin.stocks.index', ['low_stock' => 1]), $section['href']);
        $this->assertSame(
            7,
            $this->actingAs($manager)->get($section['href'])->inertiaProps('stocks.meta.total'),
            'inactive and soft-deleted product rows stay in the list, so they stay in the count',
        );
    }

    public function test_warehouse_queues_use_the_inventory_badge_services_and_ignore_other_companies(): void
    {
        $company = $this->makeCompany();
        $other = $this->makeCompany();
        $manager = $this->userWithRole($company, 'manager');
        $technician = $this->userWithRole($company, 'technician');

        foreach (['submitted', 'approved_reserved', 'preparing', 'ready_for_pickup'] as $status) {
            $this->inventoryRequest($company, $technician, $status);
        }
        foreach (['draft', 'issued', 'rejected', 'cancelled'] as $status) {
            $this->inventoryRequest($company, $technician, $status);
        }
        $otherTechnician = $this->userWithRole($other, 'technician');
        $this->inventoryRequest($other, $otherTechnician, 'submitted');
        $this->inventoryRequest($other, $otherTechnician, 'preparing');

        foreach (['awaiting_receipt', 'awaiting_receipt', 'exception_open', 'awaiting_declaration', 'correction_required', 'reconciled', 'resolved'] as $status) {
            $this->returnCase($company, $technician, $status);
        }
        $this->returnCase($other, $otherTechnician, 'awaiting_receipt');
        $this->returnCase($other, $otherTechnician, 'exception_open');

        $requests = $this->section($manager, 'warehouse_requests');
        $returns = $this->section($manager, 'return_queue');
        $this->actingAs($manager);

        $this->assertSame(1, $requests['count'], 'the manager approves what waits for them, the rest of the queue is for others');
        $this->assertSame(InventoryRequestService::getBadgeCounts($manager)['warehouse_actionable'], $requests['count']);
        $this->assertSame(['queue' => 4], $requests['breakdown']);
        $this->assertSame(InventoryRequestService::getBadgeCounts($manager)['warehouse_queue'], $requests['breakdown']['queue']);
        $this->assertSame('info', $requests['severity']);
        $this->assertSame('warehouse', $requests['group']);
        $this->assertSame(route('admin.inventory.requests.index', ['tab' => 'warehouse']), $requests['href']);

        $this->assertSame(3, $returns['count']);
        $this->assertSame(MaterialReturnService::getBadgeCounts($manager)['warehouse_return_queue'], $returns['count']);
        $this->assertSame(['exceptions' => 1], $returns['breakdown']);
        $this->assertSame('danger', $returns['severity']);
        $this->assertSame(
            route('admin.inventory.requests.index', ['tab' => 'returns', 'return_status' => 'awaiting_receipt']),
            $returns['href'],
        );
    }

    public function test_return_queue_without_exceptions_is_a_warning_and_a_resolve_only_actor_lands_on_exceptions(): void
    {
        $company = $this->makeCompany();
        $technician = $this->userWithRole($company, 'technician');
        $this->returnCase($company, $technician, 'awaiting_receipt');
        $receiver = $this->userWithRole($company, 'staff');
        $resolver = $this->userWithPermissions($company, [
            'dashboard.view', 'inventory.request.view.all', 'inventory.return.view.all', 'inventory.return.resolve',
        ]);

        $receiverSection = $this->section($receiver, 'return_queue');
        $this->assertSame(1, $receiverSection['count']);
        $this->assertSame(['exceptions' => 0], $receiverSection['breakdown']);
        $this->assertSame('warning', $receiverSection['severity']);

        $this->assertSame(
            route('admin.inventory.requests.index', ['tab' => 'returns', 'return_status' => 'exception_open']),
            $this->section($resolver, 'return_queue')['href'],
        );
    }

    public function test_warehouse_queue_cards_need_the_view_all_permission_that_makes_the_list_match(): void
    {
        $company = $this->makeCompany();
        $prepareOnly = $this->userWithPermissions($company, [
            'dashboard.view', 'inventory.request.view.own', 'inventory.request.prepare',
            'inventory.return.view.own', 'inventory.return.receive',
        ]);

        $keys = array_column($this->sectionsOf($this->actingAs($prepareOnly)->get(route('admin.dashboard'))), 'key');

        $this->assertNotContains('warehouse_requests', $keys);
        $this->assertNotContains('return_queue', $keys);
    }

    public function test_card_counts_match_the_destination_lists_they_link_to(): void
    {
        $company = $this->makeCompany();
        $manager = $this->userWithRole($company, 'manager');
        $creator = $this->fixtureCreator($company);

        $this->ticket($company, ['status' => 'open']);
        $this->ticket($company, ['status' => 'open', 'sla_deadline' => now()->subHour()]);
        $this->workOrder($company, ['status' => 'draft']);
        $this->workOrder($company, ['status' => 'waiting_review', 'created_by' => $creator->id]);
        $this->inventoryRequest($company, $creator, 'submitted');
        $this->inventoryRequest($company, $creator, 'preparing');
        $this->stockRow($company, '1.00', '2.00');

        $sections = collect($this->sectionsOf($this->actingAs($manager)->get(route('admin.dashboard'))))->keyBy('key');

        $this->assertSame(
            $sections['dispatch_unassigned']['count'],
            $this->actingAs($manager)->get($sections['dispatch_unassigned']['href'])->inertiaProps('items.meta.total'),
        );
        $this->assertSame(
            $sections['approvals']['count'],
            $this->actingAs($manager)->get($sections['approvals']['href'])->inertiaProps('items.meta.total'),
        );
        $this->assertSame(
            $sections['sla_breached']['count'],
            $this->actingAs($manager)->get($sections['sla_breached']['href'])->inertiaProps('tickets.meta.total'),
        );
        $this->assertSame(
            $sections['low_stock']['count'],
            $this->actingAs($manager)->get($sections['low_stock']['href'])->inertiaProps('stocks.meta.total'),
        );
        $this->assertSame(
            $sections['warehouse_requests']['breakdown']['queue'],
            $this->actingAs($manager)->get($sections['warehouse_requests']['href'])->inertiaProps('requests.meta.total'),
        );
        $this->assertSame(3, $sections['dispatch_unassigned']['count']);
        $this->assertSame(2, $sections['approvals']['count']);
        $this->assertSame(1, $sections['sla_breached']['count']);
        $this->assertSame(1, $sections['low_stock']['count']);
        $this->assertSame(1, $sections['warehouse_requests']['count']);
        $this->assertSame(2, $sections['warehouse_requests']['breakdown']['queue']);
    }

    public function test_my_work_count_matches_the_my_work_list(): void
    {
        $company = $this->makeCompany();
        $technician = $this->userWithRole($company, 'technician');
        $this->activeEmployeeProfile($technician);
        $this->ticket($company, ['status' => 'assigned', 'assigned_to' => $technician->id]);
        $this->workOrder($company, ['status' => 'in_progress', 'assigned_to' => $technician->id]);

        $section = $this->section($technician, 'my_work');

        $this->assertSame(2, $section['count']);
        $this->assertSame($section['count'], $this->actingAs($technician)->get($section['href'])->inertiaProps('items.meta.total'));
    }

    public function test_severity_is_graded_by_exception_type_when_something_is_pending(): void
    {
        $company = $this->makeCompany();
        $admin = $this->userWithRole($company, 'admin');
        $this->activeEmployeeProfile($admin);
        $creator = $this->fixtureCreator($company);

        $this->ticket($company, ['status' => 'assigned', 'assigned_to' => $admin->id, 'sla_deadline' => now()->subHour()]);
        $this->ticket($company, ['status' => 'open']);
        $this->workOrder($company, ['status' => 'waiting_review', 'created_by' => $creator->id]);
        $this->invoice($company);
        $this->stockRow($company, '0.00', '1.00');
        $this->inventoryRequest($company, $creator, 'preparing');
        $this->returnCase($company, $creator, 'awaiting_receipt');

        $severities = collect($this->sectionsOf($this->actingAs($admin)->get(route('admin.dashboard'))))->pluck('severity', 'key')->all();

        $this->assertSame([
            'my_work' => 'info',
            'dispatch_unassigned' => 'warning',
            'sla_breached' => 'danger',
            'sla_due_soon' => 'neutral',
            'customer_replied' => 'neutral',
            'approvals' => 'warning',
            'warehouse_requests' => 'info',
            'return_queue' => 'warning',
            'low_stock' => 'warning',
            'overdue_invoices' => 'danger',
            'invoices_due_soon' => 'neutral',
            'online_payment_exceptions' => 'neutral',
            'draft_invoices' => 'neutral',
            'online_payment_pending' => 'neutral',
        ], $severities);
    }

    public function test_dashboard_query_count_does_not_grow_with_row_volume(): void
    {
        $company = $this->makeCompany();
        $manager = $this->userWithRole($company, 'manager');
        $this->activeEmployeeProfile($manager);
        $creator = $this->fixtureCreator($company);

        $this->seedVolume($company, $creator, $manager, 1);
        $this->actingAs($manager)->get(route('admin.dashboard'))->assertOk();

        $small = $this->queryCount($manager);
        $this->seedVolume($company, $creator, $manager, 8);
        $large = $this->queryCount($manager);

        $this->assertSame($small, $large, 'dashboard queries must not scale with rows (N+1)');
    }

    private function seedVolume(Company $company, User $creator, User $manager, int $rows): void
    {
        [$login, $customer] = $this->portalCustomer($company);

        foreach (range(1, $rows) as $ignored) {
            $this->ticket($company, ['status' => 'open', 'sla_deadline' => now()->subHour()]);
            $this->ticket($company, ['status' => 'assigned', 'assigned_to' => $manager->id]);
            $this->comment($this->ticket($company, ['customer_id' => $customer->id, 'response_deadline' => now()->addMinutes(10)]), $login);
            $this->workOrder($company, ['status' => 'draft']);
            $this->workOrder($company, ['status' => 'waiting_review', 'created_by' => $creator->id]);
            $this->inventoryRequest($company, $creator, 'submitted');
            $this->returnCase($company, $creator, 'exception_open');
            $this->invoice($company);
            PaymentAttemptFactory::new()->forInvoice($this->invoice($company))->create(['created_at' => now()->subHours(2)]);
            PaymentAttemptFactory::new()->forInvoice($this->invoice($company))->status(PaymentAttempt::STATUS_EXCEPTION)->create();
            $this->stockRow($company, '0.00', '1.00');
            $this->notification($manager, ['type' => 'billing_schedule_failed']);
        }
    }

    private function queryCount(User $user): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($user)->get(route('admin.dashboard'))->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    /** @return array{0: User, 1: Customer} a customer of the company and the app login that is theirs */
    private function portalCustomer(Company $company): array
    {
        $login = User::factory()->create(['company_id' => $company->id, 'email_verified_at' => now(), 'is_active' => true]);

        return [$login, Customer::factory()->create(['company_id' => $company->id, 'user_id' => $login->id])];
    }

    private function comment(Ticket $ticket, User $author, bool $internal = false): TicketComment
    {
        return CompanyService::runAs((int) $ticket->company_id, fn (): TicketComment => TicketComment::query()->create([
            'ticket_id' => $ticket->id,
            'author_id' => $author->id,
            'body' => 'Pesan',
            'is_internal' => $internal,
        ]));
    }

    private function requestFor(User $user): Request
    {
        $request = Request::create('/admin/dashboard');
        $request->setUserResolver(fn (): User => $user);

        return $request;
    }

    /** @return array<string, mixed> */
    private function section(User $user, string $key): array
    {
        return $this->sectionOf($this->actingAs($user)->get(route('admin.dashboard'))->assertOk(), $key);
    }

    /** @return array<string, mixed> */
    private function sectionOf(TestResponse $response, string $key): array
    {
        $section = collect($this->sectionsOf($response))->firstWhere('key', $key);
        $this->assertNotNull($section, "section {$key} is missing");

        return $section;
    }

    /** @return list<array<string, mixed>> */
    private function sectionsOf(TestResponse $response): array
    {
        return array_values($response->inertiaProps('sections') ?? []);
    }
}
