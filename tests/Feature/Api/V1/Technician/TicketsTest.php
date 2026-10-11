<?php

namespace Tests\Feature\Api\V1\Technician;

use App\Models\Core\Customer;
use App\Models\Core\CustomerAddress;
use App\Models\Core\Location;
use App\Models\Core\ServiceSubscription;
use App\Models\User;
use App\Services\Core\CompanyService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Modules\Service\Database\Factories\ServicePackageFactory;
use Modules\Ticketing\Models\Ticket;
use Modules\Ticketing\Models\TicketCategory;
use Modules\Ticketing\Models\TicketComment;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;
use Tests\Traits\InteractsWithMobileApi;

class TicketsTest extends TestCase
{
    use InteractsWithMobileApi;
    use RefreshDatabase;

    private User $technician;

    private string $token;

    private User $otherTechnician;

    private string $otherToken;

    private User $admin;

    private Customer $customer;

    private TicketCategory $category;

    private Location $location;

    private ServiceSubscription $subscription;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        app()['cache']->forget('spatie.permission.cache');
        $company = $this->apiCompany();
        $this->technician = $this->technicianLogin($company);
        $this->otherTechnician = $this->technicianLogin($company);
        $this->token = $this->issueToken($this->technician, 'technician');
        $this->otherToken = $this->issueToken($this->otherTechnician, 'technician');
        $this->admin = User::factory()->create(['company_id' => $company->id, 'email_verified_at' => now(), 'is_active' => true]);
        $this->admin->assignRole('admin');
        $this->customer = Customer::factory()->create(['company_id' => $company->id, 'name' => 'Rina', 'phone' => '0812']);
        $this->category = TicketCategory::query()->create([
            'company_id' => $company->id,
            'name' => 'Support',
            'code' => 'SUP-'.Str::random(6),
            'default_sla_hours' => 24,
            'default_priority' => 'medium',
            'is_active' => true,
        ]);
        $this->location = Location::factory()->create(['company_id' => $company->id, 'name' => 'Site A', 'address' => 'Jl. Mawar', 'lat' => -6.1753924, 'lng' => 106.8271528]);
        $address = CustomerAddress::factory()->create(['company_id' => $company->id, 'customer_id' => $this->customer->id, 'lat' => -6.2, 'lng' => 106.8]);
        $this->subscription = ServiceSubscription::factory()->create([
            'company_id' => $company->id,
            'customer_id' => $this->customer->id,
            'installation_address_id' => $address->id,
            'service_package_id' => ServicePackageFactory::new()->create(['company_id' => $company->id])->id,
            'status' => 'active',
        ]);
        Notification::fake();
    }

    public function test_technician_can_show_their_ticket_with_internal_comments(): void
    {
        $ticket = $this->assignedTicket('assigned');
        $this->comment($ticket, $this->admin, 'Internal', true);
        $this->comment($ticket, $this->admin, 'Public', false);

        $this->api('GET', "technician/tickets/{$ticket->id}", token: $this->token)
            ->assertOk()
            ->assertJsonPath('data.customer.name', 'Rina')
            ->assertJsonPath('data.customer.phone', '0812')
            ->assertJsonPath('data.subscription.id', $this->subscription->id)
            ->assertJsonPath('data.location.name', 'Site A')
            ->assertJsonPath('data.location.lat', -6.1753924)
            ->assertJsonCount(2, 'data.comments')
            ->assertJsonPath('data.comments.0.is_internal', true);

        $this->api('GET', "technician/tickets/{$ticket->id}", token: $this->otherToken)->assertForbidden();
        $this->api('GET', 'technician/tickets/999999', token: $this->token)->assertNotFound();
    }

    public function test_start_records_gps_check_in_and_replay_does_not_start_twice(): void
    {
        $ticket = $this->assignedTicket('assigned');
        $key = (string) Str::uuid();

        $first = $this->api('POST', "technician/tickets/{$ticket->id}/start", [
            'check_in' => ['lat' => -6.1743924, 'lng' => 106.8271528, 'accuracy_m' => 12.5, 'captured_at' => '2026-10-14T08:30:00+07:00'],
        ], $this->token, ['Idempotency-Key' => $key])
            ->assertOk()
            ->assertJsonPath('data.status', 'on_progress');

        $ticket->refresh();
        $this->assertSame('on_progress', $ticket->status);
        $this->assertNotNull($ticket->first_response_at);
        $audit = Activity::query()->where('description', 'check_in')->sole();
        $this->assertSame('ticket', $audit->log_name);
        $this->assertSame(111, $audit->properties['distance_m']);

        $this->api('POST', "technician/tickets/{$ticket->id}/start", [
            'check_in' => ['lat' => -6.1743924, 'lng' => 106.8271528, 'accuracy_m' => 12.5, 'captured_at' => '2026-10-14T08:30:00+07:00'],
        ], $this->token, ['Idempotency-Key' => $key])
            ->assertOk()
            ->assertHeader('Idempotent-Replayed', 'true')
            ->assertContent($first->content());

        $this->assertSame(1, Activity::query()->where('description', 'check_in')->count());
    }

    public function test_unavailable_check_in_is_recorded_without_blocking_start(): void
    {
        $ticket = $this->assignedTicket('assigned');

        $this->api('POST', "technician/tickets/{$ticket->id}/start", [
            'check_in' => ['unavailable_reason' => 'permission_denied'],
        ], $this->token)->assertOk();

        $audit = Activity::query()->where('description', 'check_in')->sole();
        $this->assertSame('unavailable', $audit->properties['status']);
        $this->assertSame('permission_denied', $audit->properties['reason']);
    }

    public function test_start_twice_and_start_race_leave_state_unchanged_after_the_first_start(): void
    {
        $ticket = $this->assignedTicket('assigned');
        $this->api('POST', "technician/tickets/{$ticket->id}/start", token: $this->token)->assertOk();
        $this->api('POST', "technician/tickets/{$ticket->id}/start", token: $this->token)->assertUnprocessable();
        $this->assertSame('on_progress', $ticket->fresh()->status);

        $raced = $this->assignedTicket('assigned');
        $changed = false;
        DB::listen(function (QueryExecuted $query) use (&$changed, $raced): void {
            if (! $changed && str_contains(strtolower($query->sql), ' from `tickets` ')) {
                $changed = true;
                DB::table('tickets')->where('id', $raced->id)->update(['status' => 'on_progress']);
            }
        });

        $this->api('POST', "technician/tickets/{$raced->id}/start", token: $this->token)
            ->assertConflict()
            ->assertJsonPath('code', 'conflict');
    }

    public function test_resolve_and_internal_comment_follow_policy_and_validation(): void
    {
        $ticket = $this->assignedTicket('on_progress');

        $this->api('POST', "technician/tickets/{$ticket->id}/comments", ['body' => 'Staff note', 'is_internal' => true], $this->token)
            ->assertCreated()
            ->assertJsonPath('data.is_internal', true);
        $this->api('POST', "technician/tickets/{$ticket->id}/comments", ['body' => 'Nope'], $this->otherToken)->assertForbidden();

        $this->api('POST', "technician/tickets/{$ticket->id}/resolve", ['resolution_note' => str_repeat('x', 1001)], $this->token)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['resolution_note']);
        $this->api('POST', "technician/tickets/{$ticket->id}/resolve", ['resolution_note' => 'Fixed'], $this->otherToken)->assertForbidden();
        $this->api('POST', "technician/tickets/{$ticket->id}/resolve", ['resolution_note' => 'Fixed'], $this->token)
            ->assertOk()
            ->assertJsonPath('data.status', 'resolved')
            ->assertJsonPath('data.resolution_note', 'Fixed');

        $this->assertSame('resolved', $ticket->fresh()->status);
        $this->postJson("/api/v1/technician/tickets/{$ticket->id}/close", [], ['Authorization' => 'Bearer '.$this->token])->assertNotFound();
    }

    public function test_customer_view_never_sees_internal_technician_comment(): void
    {
        $ticket = $this->assignedTicket('on_progress');
        $customerUser = User::factory()->create(['company_id' => $this->technician->company_id, 'email_verified_at' => now(), 'is_active' => true]);
        $customerUser->assignRole('customer');
        $this->customer->update(['user_id' => $customerUser->id]);
        $customerToken = $this->issueToken($customerUser, 'customer');

        $this->api('POST', "technician/tickets/{$ticket->id}/comments", ['body' => 'Internal', 'is_internal' => true], $this->token)->assertCreated();
        $this->api('POST', "technician/tickets/{$ticket->id}/comments", ['body' => 'Public', 'is_internal' => false], $this->token)->assertCreated();

        $this->api('GET', "customer/tickets/{$ticket->id}", token: $customerToken)
            ->assertOk()
            ->assertJsonCount(1, 'data.comments')
            ->assertJsonPath('data.comments.0.body', 'Public');
    }

    private function assignedTicket(string $status): Ticket
    {
        return Ticket::query()->create([
            'company_id' => $this->technician->company_id,
            'code' => 'TKT-'.Str::random(10),
            'title' => 'Downlink issue',
            'description' => 'Customer offline',
            'source' => 'internal',
            'category_id' => $this->category->id,
            'status' => $status,
            'priority' => 'medium',
            'customer_id' => $this->customer->id,
            'subscription_id' => $this->subscription->id,
            'location_id' => $this->location->id,
            'assigned_to' => $this->technician->id,
            'created_by' => $this->admin->id,
            'sla_deadline' => now()->addDay(),
        ]);
    }

    private function comment(Ticket $ticket, User $author, string $body, bool $internal): TicketComment
    {
        return CompanyService::runAs((int) $ticket->company_id, fn () => TicketComment::query()->create([
            'ticket_id' => $ticket->id,
            'author_id' => $author->id,
            'body' => $body,
            'is_internal' => $internal,
        ]));
    }
}
