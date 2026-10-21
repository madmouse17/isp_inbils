<?php

namespace Tests\Feature\Inventory;

use App\Models\Core\Company;
use App\Models\User;
use App\Services\Core\CompanyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Inventory\Models\InventoryRequest;
use Modules\Inventory\Services\InventoryRequestService;
use Tests\TestCase;

/**
 * F4-U12: the warehouse card counted what waited for the manager. `warehouse_actionable` counts what this person can act
 * on now, and nothing else: the same answer InventoryRequestPolicy gives per request, worked out in one query. The
 * dashboard (batch E) reads it; `warehouse_queue` stays the whole queue.
 */
class ActionableQueueCountTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $technician;

    /** Approves and rejects, prepares and hands over: the maker-checker exclusions are what is under test. */
    private User $everything;

    private User $decider;

    private User $rejecter;

    private User $warehouse;

    private User $preparer;

    private User $issuer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create(['is_active' => true]);
        CompanyService::useCompanyForTests($this->company->id);

        $this->technician = $this->person([]);
        $this->decider = $this->person(['inventory.request.approve']);
        $this->rejecter = $this->person(['inventory.request.reject']);
        $this->warehouse = $this->person(['inventory.request.prepare', 'inventory.request.issue']);
        $this->preparer = $this->person(['inventory.request.prepare']);
        $this->issuer = $this->person(['inventory.request.issue']);
        $this->everything = $this->person(['inventory.request.approve', 'inventory.request.reject', 'inventory.request.prepare', 'inventory.request.issue']);
        $this->queue();
    }

    public function test_each_person_counts_what_they_can_act_on_and_the_queue_stays_whole(): void
    {
        $expected = [
            // [who, actionable, whole queue]
            'technician' => [$this->technician, null, null],
            'decider' => [$this->decider, 1, 9],       // R1; R2 is their own
            'rejecter' => [$this->rejecter, 2, null],  // R1, R2: may reject, not approve, so no queue figure of old
            'warehouse' => [$this->warehouse, 6, 9],   // R3 R4 R5 prepare, R6 finish, R7 R9 hand over; R8 is their own
            'preparer' => [$this->preparer, 4, 9],     // R3 R4 R5 and R6
            'issuer' => [$this->issuer, 3, 9],         // R7 R8 R9
            'everything' => [$this->everything, 7, 9], // R1 R2 R4 R5 R6 R7 R8; not R3 and R9, which they approved
        ];

        foreach ($expected as $name => [$user, $actionable, $queue]) {
            $counts = InventoryRequestService::getBadgeCounts($user);

            $this->assertSame($actionable, $counts['warehouse_actionable'], "{$name}: actionable");
            $this->assertSame($queue, $counts['warehouse_queue'], "{$name}: the whole queue");
            $this->assertSame($actionable, InventoryRequestService::actionableQueueCount($user), "{$name}: direct");
        }
    }

    public function test_the_count_is_what_the_policy_allows_request_by_request(): void
    {
        foreach ([$this->decider, $this->rejecter, $this->warehouse, $this->preparer, $this->issuer, $this->everything] as $user) {
            $byPolicy = InventoryRequest::withoutCompany()->where('company_id', $this->company->id)->get()
                ->filter(fn (InventoryRequest $request): bool => collect(['approve', 'reject', 'prepare', 'ready', 'issue'])->contains(fn (string $ability): bool => $user->can($ability, $request)))
                ->count();

            $this->assertSame($byPolicy, InventoryRequestService::actionableQueueCount($user), 'permissions of the user: '.$user->getAllPermissions()->pluck('name')->implode(', '));
        }
    }

    public function test_another_companys_queue_is_never_counted(): void
    {
        $other = Company::factory()->create(['is_active' => true]);
        $stranger = User::factory()->create(['company_id' => $other->id]);
        InventoryRequest::query()->create(['company_id' => $other->id, 'code' => 'REQ-OTHER', 'requested_by' => $stranger->id, 'status' => 'submitted']);

        $this->assertSame(1, InventoryRequestService::actionableQueueCount($this->decider), 'still the one of this company');
    }

    public function test_the_count_is_one_query_however_long_the_queue_is(): void
    {
        $this->assertSame(7, InventoryRequestService::actionableQueueCount($this->everything));

        DB::enableQueryLog();
        InventoryRequestService::actionableQueueCount($this->everything);
        $first = count(DB::getQueryLog());

        foreach (range(1, 20) as $ignored) {
            $this->request('submitted', $this->technician);
        }
        DB::flushQueryLog();
        InventoryRequestService::actionableQueueCount($this->everything);
        $second = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame($first, $second);
        $this->assertSame(27, InventoryRequestService::actionableQueueCount($this->everything));
    }

    /**
     * The nine requests of the queue and the ones around it:
     * R1 and R2 wait for approval (R2 was asked by the decider), R3 R4 R5 are approved (by the person who may do
     * everything, by the decider, by nobody on record), R6 is being prepared, R7 R8 R9 are ready (R8 was asked by the
     * warehouse person, R9 approved by the person who may do everything).
     */
    private function queue(): void
    {
        $this->request('submitted', $this->technician);                                       // R1
        $this->request('submitted', $this->decider);                                          // R2
        $this->request('approved_reserved', $this->technician, approver: $this->everything);  // R3
        $this->request('approved_reserved', $this->technician, approver: $this->decider);     // R4
        $this->request('approved_reserved', $this->technician);                               // R5
        $this->request('preparing', $this->technician, approver: $this->decider);             // R6
        $this->request('ready_for_pickup', $this->technician, approver: $this->decider);      // R7
        $this->request('ready_for_pickup', $this->warehouse, approver: $this->decider);       // R8
        $this->request('ready_for_pickup', $this->technician, approver: $this->everything);   // R9

        foreach (['draft', 'issued', 'rejected', 'cancelled'] as $closed) {
            $this->request($closed, $this->technician, approver: $this->decider);
        }
    }

    private function request(string $status, User $requester, ?User $approver = null): InventoryRequest
    {
        return InventoryRequest::query()->create([
            'company_id' => $this->company->id,
            'code' => 'REQ-'.Str::upper(Str::random(8)),
            'requested_by' => $requester->id,
            'approved_by' => $approver?->id,
            'status' => $status,
        ]);
    }

    /** @param  list<string>  $permissions */
    private function person(array $permissions): User
    {
        $user = User::factory()->create(['company_id' => $this->company->id]);

        if ($permissions !== []) {
            $user->givePermissionTo($permissions);
        }

        return $user;
    }
}
