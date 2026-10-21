<?php

declare(strict_types=1);

namespace Tests\Feature\Ticketing;

use App\Models\Core\Company;
use App\Models\Core\Customer;
use App\Models\Core\CustomerAddress;
use App\Models\Core\Location;
use App\Models\Core\ServiceSubscription;
use App\Models\User;
use App\Support\OptimisticLock;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Modules\NetworkAsset\Database\Factories\NetworkAssetFactory;
use Modules\Service\Database\Factories\ServicePackageFactory;
use Modules\Service\Models\ServicePackage;
use Modules\Service\Models\SLATier;
use Modules\SPK\Database\Factories\WorkOrderFactory;
use Modules\Ticketing\Models\Ticket;
use Modules\Ticketing\Models\TicketCategory;
use Modules\Ticketing\Models\TicketComment;
use Modules\Ticketing\Services\TicketService;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;
use Tests\Traits\CreatesCompanyUser;
use Tests\Traits\InteractsWithMobileApi;

/**
 * Master lifecycle on tickets (spec .scratch/master-lifecycle D2, D4, D5, D6): a closed ticket keeps the category and
 * the assignee as they were when it closed, the SLA targets come from the package version the subscription was sold
 * under, a deleted ticket can be listed and restored, and the parents a ticket points at stay readable once deleted.
 */
class TicketSnapshotTest extends TestCase
{
    use CreatesCompanyUser;
    use InteractsWithMobileApi;
    use RefreshDatabase;

    private User $admin;

    private User $manager;

    private User $technician;

    private TicketCategory $category;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->admin = $this->createCompanyUser();
        $this->technician = $this->technicianLogin($this->company(), ['name' => 'Budi Santoso']);
        $this->manager = $this->staff('manager');
        $this->category = $this->category('Gangguan');
    }

    public function test_closing_freezes_the_category_and_the_assignee_names(): void
    {
        $ticket = $this->ticket('resolved');
        $this->assertNull($ticket->fresh()->category_name_snapshot);

        $this->close($ticket);

        $closed = $ticket->fresh();
        $this->assertSame('closed', $closed->status);
        $this->assertSame('Gangguan', $closed->category_name_snapshot);
        $this->assertSame('Budi Santoso', $closed->assignee_name_snapshot);
    }

    public function test_the_names_are_read_under_the_row_lock_and_written_with_the_status(): void
    {
        $ticket = $this->ticket('resolved');
        $this->actingAs($this->manager);

        DB::enableQueryLog();
        TicketService::close($ticket);
        $queries = collect(DB::getQueryLog())->pluck('query')->values();

        $lock = $queries->search(fn (string $sql): bool => str_contains($sql, 'from `tickets`') && str_contains($sql, 'for update'));
        $category = $queries->search(fn (string $sql): bool => str_contains($sql, 'from `ticket_categories`'));
        $updates = $queries->filter(fn (string $sql): bool => str_starts_with($sql, 'update `tickets`'));

        $this->assertNotFalse($lock);
        $this->assertNotFalse($category);
        $this->assertGreaterThan($lock, $category, 'the category is read after the ticket is locked');
        $this->assertCount(1, $updates);
        $this->assertGreaterThan($category, $updates->keys()->first(), 'the names are written after they are read');
        foreach (['status', 'closed_at', 'category_name_snapshot', 'assignee_name_snapshot'] as $column) {
            $this->assertStringContainsString("`{$column}` = ?", $updates->first());
        }
    }

    public function test_a_ticket_whose_category_was_deleted_can_still_be_closed(): void
    {
        $ticket = $this->ticket('resolved');
        DB::table('ticket_categories')->where('id', $this->category->id)->update(['deleted_at' => now()]);

        $this->close($ticket);

        $this->assertSame('Gangguan', $ticket->fresh()->category_name_snapshot);
    }

    public function test_the_apps_show_the_frozen_names_of_a_closed_ticket_and_the_live_ones_of_the_rest(): void
    {
        [$customerUser, $customer] = $this->customerLogin($this->company());
        $closed = $this->ticket('resolved', ['customer_id' => $customer->id]);
        $working = $this->ticket('on_progress', ['customer_id' => $customer->id]);
        $this->close($closed);

        $this->category->update(['name' => 'Gangguan Jaringan']);
        $this->technician->forceFill(['name' => 'Andi Wijaya'])->save();

        $customerToken = $this->issueToken($customerUser, 'customer');
        $list = collect($this->api('GET', 'customer/tickets', token: $customerToken)->assertOk()->json('data'))->keyBy('id');
        $detail = $this->api('GET', "customer/tickets/{$closed->public_id}", token: $customerToken)->assertOk()->json('data');
        $technicianView = $this->api('GET', "technician/tickets/{$closed->public_id}", token: $this->issueToken($this->technician, 'technician'))
            ->assertOk()->json('data');

        foreach ([$list[$closed->public_id], $detail, $technicianView] as $row) {
            $this->assertSame('Gangguan', $row['category']['name']);
            $this->assertSame('Budi', $row['handler_first_name']);
        }
        $this->assertSame('Gangguan Jaringan', $list[$working->public_id]['category']['name']);
        $this->assertSame('Andi', $list[$working->public_id]['handler_first_name']);
    }

    public function test_the_ticket_page_and_list_carry_the_frozen_names(): void
    {
        $ticket = $this->ticket('resolved');
        $this->close($ticket);
        $this->category->update(['name' => 'Gangguan Jaringan']);
        $this->technician->forceFill(['name' => 'Andi Wijaya'])->save();

        $this->actingAs($this->admin);
        $show = $this->get(route('admin.tickets.show', $ticket))->assertOk()->viewData('page')['props']['ticket']['data'];
        $row = $this->get(route('admin.tickets.index'))->assertOk()->viewData('page')['props']['tickets']['data'][0];

        foreach ([$show, $row] as $payload) {
            $this->assertSame('Gangguan', $payload['category_name_snapshot']);
            $this->assertSame('Budi Santoso', $payload['assignee_name_snapshot']);
            $this->assertNull($payload['deleted_at']);
            $this->assertNull($payload['references']);
        }
        $this->assertSame('Gangguan Jaringan', $show['category']['name'], 'the live relation is untouched; the page prefers the snapshot');
    }

    public function test_the_sla_deadlines_follow_the_version_tier_after_the_package_and_the_tier_moved_on(): void
    {
        $this->travelTo(Carbon::parse('2026-03-02 09:00:00'));
        $gold = $this->tier('Gold', response: 2, resolution: 6);
        $silver = $this->tier('Silver', response: 1, resolution: 2);
        $package = ServicePackageFactory::new()->create(['company_id' => $this->admin->company_id, 'sla_tier_id' => $gold->id]);
        $sold = $this->subscriptionOn($package);

        // The package moves to Silver and Gold is switched off: only later sales are affected.
        $package->update(['sla_tier_id' => $silver->id]);
        $gold->forceFill(['is_active' => false])->save();
        $later = $this->subscriptionOn($package);

        $this->actingAs($this->admin);
        $old = TicketService::create($this->payload($sold), $this->admin->id);
        $new = TicketService::create($this->payload($later), $this->admin->id);

        $this->assertSame('2026-03-02 15:00:00', $old->sla_deadline->toDateTimeString());
        $this->assertSame('2026-03-02 11:00:00', $old->response_deadline->toDateTimeString());
        $this->assertSame(
            ['type' => 'tier', 'name' => 'Gold', 'response_hours' => 2, 'resolution_hours' => 6, 'urgent_halved' => false, 'calendar' => '24h'],
            TicketService::deadlineSource($old),
        );
        $this->assertSame('2026-03-02 11:00:00', $new->sla_deadline->toDateTimeString());
        $this->assertSame('2026-03-02 10:00:00', $new->response_deadline->toDateTimeString());
        $this->assertSame(
            ['type' => 'tier', 'name' => 'Silver', 'response_hours' => 1, 'resolution_hours' => 2, 'urgent_halved' => false, 'calendar' => '24h'],
            TicketService::deadlineSource($new),
        );

        $urgent = TicketService::update($old, ['priority' => 'urgent'], OptimisticLock::version($old->fresh()));

        $this->assertSame('2026-03-02 12:00:00', $urgent->sla_deadline->toDateTimeString(), 'a priority change halves the version tier, not the package tier');
        $this->assertSame('2026-03-02 10:00:00', $urgent->response_deadline->toDateTimeString());
    }

    public function test_the_status_filter_lists_deleted_tickets_and_knows_the_five_statuses(): void
    {
        $this->ticket('open', ['code' => 'TKT-OPEN', 'assigned_to' => null]);
        $this->ticket('closed', ['code' => 'TKT-CLOSED']);
        $gone = $this->ticket('closed', ['code' => 'TKT-GONE']);
        $this->actingAs($this->admin)->delete(route('admin.tickets.destroy', $gone))->assertRedirect();

        $this->assertSame(['TKT-CLOSED', 'TKT-OPEN'], $this->listed());
        $this->assertSame(['TKT-OPEN'], $this->listed(['status' => 'open']));
        $this->assertSame(['TKT-CLOSED'], $this->listed(['status' => 'closed']));
        $this->assertSame(['TKT-GONE'], $this->listed(['status' => 'dihapus']));
        $this->assertSame(['TKT-CLOSED', 'TKT-GONE', 'TKT-OPEN'], $this->listed(['status' => 'semua']));
        $this->assertSame(['TKT-CLOSED', 'TKT-OPEN'], $this->listed(['status' => 'unknown']), 'an unknown value is the default view');

        $export = $this->get(route('admin.tickets.export', ['format' => 'csv', 'status' => 'dihapus']))->assertOk()->streamedContent();
        $this->assertStringContainsString('TKT-GONE', $export);
        $this->assertStringNotContainsString('TKT-OPEN', $export);

        $page = $this->get(route('admin.tickets.index', ['status' => 'dihapus']))->assertOk()->viewData('page')['props'];
        $this->assertSame('dihapus', $page['filters']['status']);
        $this->assertNotNull($page['tickets']['data'][0]['deleted_at']);
        $this->assertTrue($page['can']['update']);
        $this->assertTrue($page['can']['delete']);

        $managerCan = $this->actingAs($this->manager)->get(route('admin.tickets.index'))->assertOk()->viewData('page')['props']['can'];
        $this->assertTrue($managerCan['update']);
        $this->assertFalse($managerCan['delete']);
    }

    public function test_a_deleted_ticket_is_restored_with_its_status_and_names(): void
    {
        $ticket = $this->ticket('resolved', ['code' => 'TKT-BACK']);
        $this->close($ticket);
        $this->actingAs($this->admin)->delete(route('admin.tickets.destroy', $ticket))->assertRedirect();
        $this->get(route('admin.tickets.show', $ticket))->assertNotFound();

        $this->post(route('admin.tickets.restore', $ticket->public_id))
            ->assertRedirect()
            ->assertSessionHas('success', 'Tiket dipulihkan.');
        $restoreLogs = fn (): int => Activity::query()->where('log_name', 'ticket')->where('description', 'restored')->count();
        $logged = $restoreLogs();
        $this->post(route('admin.tickets.restore', $ticket->public_id))->assertRedirect();

        $restored = Ticket::query()->findOrFail($ticket->id);
        $this->assertSame('closed', $restored->status);
        $this->assertSame('Gangguan', $restored->category_name_snapshot);
        $this->assertSame(['TKT-BACK'], $this->listed());
        $this->assertSame([], $this->listed(['status' => 'dihapus']));
        $this->assertGreaterThan(0, $logged);
        $this->assertSame($logged, $restoreLogs(), 'restoring a live ticket logs nothing');
    }

    public function test_restore_needs_ticket_delete_and_stays_inside_the_company(): void
    {
        $ticket = $this->ticket('closed');
        $ticket->delete();
        $foreignAdmin = User::factory()->create(['company_id' => Company::factory()->create(['is_active' => true])->id]);
        $this->grantRole($foreignAdmin, 'admin');

        $this->actingAs($this->manager)->post(route('admin.tickets.restore', $ticket->public_id))->assertForbidden();
        $this->actingAs($foreignAdmin)->post(route('admin.tickets.restore', $ticket->public_id))->assertNotFound();

        $this->assertSoftDeleted($ticket);
    }

    public function test_the_parents_of_a_ticket_stay_readable_once_they_are_deleted(): void
    {
        $companyId = $this->admin->company_id;
        $customer = Customer::factory()->create(['company_id' => $companyId]);
        $subscription = $this->subscriptionOn(ServicePackageFactory::new()->create(['company_id' => $companyId]));
        $location = Location::factory()->create(['company_id' => $companyId]);
        $asset = NetworkAssetFactory::new()->create(['company_id' => $companyId]);
        $workOrder = WorkOrderFactory::new()->create(['company_id' => $companyId, 'created_by' => $this->admin->id]);
        $ticket = $this->ticket('closed', [
            'customer_id' => $customer->id,
            'subscription_id' => $subscription->id,
            'location_id' => $location->id,
            'network_asset_id' => $asset->id,
            'spawned_spk_id' => $workOrder->id,
        ]);
        $comment = TicketComment::query()->create(['ticket_id' => $ticket->id, 'author_id' => $this->admin->id, 'body' => 'Sudah dicek.']);

        foreach ([
            'ticket_categories' => $this->category->id,
            'customers' => $customer->id,
            'service_subscriptions' => $subscription->id,
            'locations' => $location->id,
            'network_assets' => $asset->id,
            'work_orders' => $workOrder->id,
        ] as $table => $id) {
            DB::table($table)->where('id', $id)->update(['deleted_at' => now()]);
        }
        $ticket->delete();

        $loaded = Ticket::withTrashed()
            ->with(['category', 'customer', 'subscription', 'networkAsset', 'location', 'spawnedSpk'])
            ->findOrFail($ticket->id);

        foreach (['category', 'customer', 'subscription', 'networkAsset', 'location', 'spawnedSpk'] as $relation) {
            $this->assertNotNull($loaded->{$relation}, $relation);
            $this->assertTrue($loaded->{$relation}->trashed(), $relation);
        }
        $this->assertTrue(TicketComment::query()->with('ticket')->findOrFail($comment->id)->ticket->trashed());
    }

    public function test_the_status_list_is_the_status_column_and_the_filter_keys(): void
    {
        $column = DB::selectOne("show columns from tickets where Field = 'status'");
        preg_match_all("/'([^']+)'/", $column->Type, $values);

        $this->assertSame($values[1], Ticket::STATUSES);
        $this->assertSame(Ticket::STATUSES, array_keys(Ticket::lifecycleFilters()));
    }

    private function company(): Company
    {
        return Company::query()->findOrFail($this->admin->company_id);
    }

    private function staff(string $role): User
    {
        $user = User::factory()->create(['company_id' => $this->admin->company_id]);
        $this->grantRole($user, $role);

        return $user;
    }

    private function category(string $name): TicketCategory
    {
        return TicketCategory::query()->create([
            'company_id' => $this->admin->company_id,
            'name' => $name,
            'code' => 'CAT-'.Str::random(6),
            'default_sla_hours' => 24,
            'default_response_hours' => 4,
            'default_priority' => 'medium',
            'is_active' => true,
        ]);
    }

    /** @param  array<string, mixed>  $overrides */
    private function ticket(string $status, array $overrides = []): Ticket
    {
        return Ticket::query()->create([
            'company_id' => $this->admin->company_id,
            'code' => 'TKT-'.Str::random(8),
            'title' => 'Internet mati',
            'source' => 'internal',
            'category_id' => $this->category->id,
            'status' => $status,
            'priority' => 'medium',
            'assigned_to' => $this->technician->id,
            'created_by' => $this->admin->id,
            'sla_deadline' => now()->addDay(),
            ...$overrides,
        ]);
    }

    /** The checker closes the work the technician resolved. */
    private function close(Ticket $ticket): void
    {
        $this->actingAs($this->manager)->post(route('admin.tickets.close', $ticket))->assertRedirect();
    }

    /** @return list<string> the ticket codes the admin's list shows for the query, sorted */
    private function listed(array $query = []): array
    {
        $rows = $this->actingAs($this->admin)->get(route('admin.tickets.index', $query))->assertOk()->viewData('page')['props']['tickets']['data'];

        return collect($rows)->pluck('code')->sort()->values()->all();
    }

    private function tier(string $name, int $response, int $resolution): SLATier
    {
        return SLATier::factory()->create([
            'company_id' => $this->admin->company_id,
            'name' => $name,
            'response_time_hours' => $response,
            'resolution_time_hours' => $resolution,
            'is_active' => true,
        ]);
    }

    /** A subscription sold under the latest version of the package. */
    private function subscriptionOn(ServicePackage $package): ServiceSubscription
    {
        $customer = Customer::factory()->create(['company_id' => $this->admin->company_id]);
        $address = CustomerAddress::factory()->create(['company_id' => $this->admin->company_id, 'customer_id' => $customer->id]);

        return ServiceSubscription::factory()->create([
            'company_id' => $this->admin->company_id,
            'customer_id' => $customer->id,
            'service_package_id' => $package->id,
            'installation_address_id' => $address->id,
            'status' => 'active',
        ]);
    }

    /** @return array<string, mixed> */
    private function payload(ServiceSubscription $subscription): array
    {
        return [
            'title' => 'Internet mati',
            'source' => 'customer',
            'category_id' => $this->category->id,
            'priority' => 'medium',
            'customer_id' => $subscription->customer_id,
            'subscription_id' => $subscription->id,
        ];
    }
}
