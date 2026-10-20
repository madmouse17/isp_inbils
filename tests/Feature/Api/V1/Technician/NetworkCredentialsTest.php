<?php

namespace Tests\Feature\Api\V1\Technician;

use App\Http\Middleware\Api\EnforceIdempotency;
use App\Models\Core\Company;
use App\Models\Core\ServiceSubscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Modules\NetworkAsset\Models\NetworkAccount;
use Modules\SPK\Models\WorkOrder;
use Modules\SPK\Models\WorkOrderAssignment;
use Modules\SPK\Services\SpkService;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;
use Tests\Traits\BuildsNetworkFixtures;
use Tests\Traits\BuildsSubscriptionBilling;
use Tests\Traits\CreatesCompanyUser;
use Tests\Traits\InteractsWithMobileApi;
use Tests\Traits\InteractsWithRadius;

/**
 * K18: the PPPoE login of an installation reaches one person, the technician the SPK is assigned to now, while it is assigned
 * or in progress, through one audited, rate-limited call whose answer is never cached. A reassignment ends the access of the
 * previous technician, also for a replay of the key they used.
 */
class NetworkCredentialsTest extends TestCase
{
    use BuildsNetworkFixtures;
    use BuildsSubscriptionBilling;
    use CreatesCompanyUser;
    use InteractsWithMobileApi;
    use InteractsWithRadius;
    use RefreshDatabase;

    private User $admin;

    private Company $company;

    private ServiceSubscription $subscription;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->fakeRadius();
        $this->admin = $this->createCompanyUser();
        $this->company = Company::query()->findOrFail($this->admin->company_id);
        $this->subscription = $this->subscriptionIn($this->company, 'pending', null, $this->packageWithRadiusProfile($this->company, 'home-20m'));
    }

    /** @param  array<string, mixed>  $attributes */
    private function installation(array $attributes = [], ?ServiceSubscription $subscription = null, string $type = 'installation'): WorkOrder
    {
        $subscription ??= $this->subscription;

        return WorkOrder::forceCreate($attributes + [
            'company_id' => $this->company->id,
            'code' => 'SPK-2026-'.str_pad((string) ++$this->sequence, 4, '0', STR_PAD_LEFT),
            'type' => $type,
            'title' => 'Pasang baru',
            'status' => 'generated',
            'source' => 'manual',
            'priority' => 'medium',
            'customer_id' => $subscription->customer_id,
            'subscription_id' => $subscription->id,
            'created_by' => $this->admin->id,
        ]);
    }

    private function assignTo(WorkOrder $workOrder, User $technician): WorkOrder
    {
        $this->actingAs($this->admin);
        $workOrder = $workOrder->fresh();

        return SpkService::assign($workOrder, $technician->id, $this->admin->id, $workOrder->assigned_to, $workOrder->updated_at->utc()->format('Y-m-d\\TH:i:s.v\\Z'));
    }

    /** @return array{0: User, 1: string, 2: WorkOrder, 3: ?NetworkAccount} the technician, their token, the assigned SPK and its account, when one was made */
    private function assignedInstallation(?ServiceSubscription $subscription = null): array
    {
        $subscription ??= $this->subscription;
        $technician = $this->technicianLogin($this->company);
        $workOrder = $this->assignTo($this->installation(subscription: $subscription), $technician);

        return [$technician, $this->issueToken($technician, 'technician'), $workOrder, NetworkAccount::query()->where('subscription_id', $subscription->id)->first()];
    }

    private function reveal(WorkOrder $workOrder, string $token, ?string $key = null): TestResponse
    {
        return $this->api('POST', "technician/work-orders/{$workOrder->public_id}/network-credentials", token: $token, headers: $key === null ? [] : ['Idempotency-Key' => $key]);
    }

    public function test_assignee_reveals_secret_audited_with_no_store_header(): void
    {
        [$technician, $token, $workOrder, $account] = $this->assignedInstallation();

        $response = $this->reveal($workOrder, $token)->assertOk();

        $response->assertExactJson(['data' => ['username' => $account->username, 'password' => $account->password]]);
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));

        $audit = Activity::query()->where('log_name', 'network_account')->where('description', 'secret_revealed')->sole();
        $this->assertSame($technician->id, $audit->causer_id, 'the audit row names who asked');
        $this->assertSame('technician_app', $audit->getExtraProperty('via'));
        $this->assertSame($workOrder->id, $audit->getExtraProperty('work_order_id'));
        $this->assertSame($account->username, $audit->getExtraProperty('username'));
        $this->assertStringNotContainsString($account->password, json_encode($audit->properties));
    }

    public function test_every_reveal_is_audited_and_the_secret_is_in_no_audit_row(): void
    {
        [, $token, $workOrder, $account] = $this->assignedInstallation();

        $this->reveal($workOrder, $token)->assertOk();
        $this->reveal($workOrder, $token)->assertOk();

        $this->assertSame(2, Activity::query()->where('description', 'secret_revealed')->count());
        foreach (DB::table('activity_log')->pluck('properties') as $properties) {
            $this->assertStringNotContainsString($account->password, (string) $properties);
        }
    }

    public function test_the_secret_is_not_kept_for_an_idempotency_replay(): void
    {
        [$technician, $token, $workOrder] = $this->assignedInstallation();
        $key = (string) Str::uuid();

        $first = $this->reveal($workOrder, $token, $key)->assertOk();
        $this->assertNull(Cache::get(EnforceIdempotency::cacheKey($technician->id, $key)), 'nothing of the answer, and not the key, is kept');

        $again = $this->reveal($workOrder, $token, $key)->assertOk()->assertHeaderMissing('Idempotent-Replayed');

        $this->assertSame($first->json('data.username'), $again->json('data.username'));
        $this->assertSame(2, Activity::query()->where('description', 'secret_revealed')->count(), 'the second ask ran again, so it is audited again');
    }

    public function test_idempotency_keeps_a_plain_answer_and_never_a_no_store_one(): void
    {
        Route::middleware(['api', 'api.technician'])->prefix('api/v1/_probe')->group(function (): void {
            Route::post('plain', fn () => response()->json(['data' => 'plain']));
            Route::post('secret', fn () => response()->json(['data' => 'secret'])->header('Cache-Control', 'no-store, private'));
        });
        $technician = $this->technicianLogin($this->company);
        $token = $this->issueToken($technician, 'technician');
        $plainKey = (string) Str::uuid();
        $secretKey = (string) Str::uuid();

        $this->api('POST', '_probe/plain', token: $token, headers: ['Idempotency-Key' => $plainKey])->assertOk();
        $this->api('POST', '_probe/secret', token: $token, headers: ['Idempotency-Key' => $secretKey])->assertOk();

        $this->assertSame('done', Cache::get(EnforceIdempotency::cacheKey($technician->id, $plainKey))['state'], 'the control: an ordinary answer is kept for the replay');
        $this->assertNull(Cache::get(EnforceIdempotency::cacheKey($technician->id, $secretKey)), 'a no-store answer leaves nothing behind, not even its key');
        $this->api('POST', '_probe/plain', token: $token, headers: ['Idempotency-Key' => $plainKey])->assertHeader('Idempotent-Replayed', 'true');
        $this->api('POST', '_probe/secret', token: $token, headers: ['Idempotency-Key' => $secretKey])->assertHeaderMissing('Idempotent-Replayed');
    }

    public function test_in_progress_reassignment_succeeds_and_hands_reveal_access_to_the_new_assignee(): void
    {
        Storage::fake('public');
        [$first, $firstToken, $workOrder, $account] = $this->assignedInstallation();
        $key = (string) Str::uuid();
        $secret = $account->password;
        $url = fn (string $action): string => "technician/work-orders/{$workOrder->public_id}/{$action}";

        $this->api('POST', $url('en-route'), token: $firstToken)->assertOk();
        $this->api('POST', $url('start'), ['check_in' => ['lat' => -6.2, 'lng' => 106.8166667]], $firstToken)
            ->assertOk()
            ->assertJsonPath('data.status', 'in_progress');
        $workOrder->addMediaFromString('proof')->usingFileName('proof.jpg')
            ->withCustomProperties(['company_id' => $this->company->id, 'type' => 'photo', 'uploaded_by' => $first->id])
            ->toMediaCollection('evidence', 'public');
        $this->reveal($workOrder, $firstToken, $key)->assertOk()->assertJsonPath('data.password', $secret);

        $second = $this->technicianLogin($this->company);
        $secondToken = $this->issueToken($second, 'technician');
        $running = $workOrder->fresh();
        $this->actingAs($this->admin)->post(route('admin.spk.assign', $workOrder), [
            'technician_id' => $second->public_id,
            'expected_assigned_to' => $first->public_id,
            'expected_updated_at' => $running->updated_at->utc()->format('Y-m-d\\TH:i:s.v\\Z'),
        ])->assertRedirect()->assertSessionHas('success');

        $fresh = $workOrder->fresh();
        $this->assertSame(['assigned', $second->id], [$fresh->status, $fresh->assigned_to]);
        $this->assertNull($fresh->en_route_at);
        $this->assertNull($fresh->started_at);
        $this->assertNull($fresh->submitted_at);
        $this->assertSame(1, $workOrder->getMedia('evidence')->count(), 'the first technician\'s evidence stays with the SPK');
        $this->assertSame(1, DB::table('activity_log')->where('log_name', 'work_order')->where('description', 'check_in')->where('subject_id', $workOrder->id)->count(), 'the check-in stays in the audit history');
        $this->assertSame(1, DB::table('activity_log')->where('log_name', 'work_order')->where('description', 'started')->where('subject_id', $workOrder->id)->count(), 'the first start stays in the audit history');

        $assignments = WorkOrderAssignment::query()->where('work_order_id', $workOrder->id)->orderBy('id')->get();
        $this->assertSame([$first->id, $second->id], $assignments->pluck('technician_id')->all());
        $this->assertNotNull($assignments[0]->unassigned_at);
        $this->assertSame($this->admin->id, $assignments[0]->unassigned_by);
        $this->assertNull($assignments[1]->unassigned_at);

        $old = $this->reveal($workOrder, $firstToken, $key)->assertNotFound()->assertHeaderMissing('Idempotent-Replayed');
        $this->assertStringNotContainsString($secret, $old->getContent());
        $this->api('GET', "technician/work-orders/{$workOrder->public_id}", token: $firstToken)->assertNotFound();
        $this->api('GET', "technician/work-orders/{$workOrder->public_id}", token: $secondToken)
            ->assertOk()
            ->assertJsonPath('data.status', 'assigned')
            ->assertJsonPath('data.network_account.can_reveal', true);
        $this->reveal($workOrder, $secondToken)->assertOk()->assertJsonPath('data.password', $account->fresh()->password);
    }

    public function test_previous_assignee_gets_404_after_reassignment_and_the_secret_they_saw_no_longer_works(): void
    {
        [, $firstToken, $workOrder, $account] = $this->assignedInstallation();
        $second = $this->technicianLogin($this->company);
        $secondToken = $this->issueToken($second, 'technician');
        $seen = $this->reveal($workOrder, $firstToken)->assertOk()->json('data.password');

        $this->assignTo($workOrder, $second);

        $refused = $this->reveal($workOrder, $firstToken)->assertNotFound();
        $this->assertStringNotContainsString($seen, $refused->getContent());
        $fresh = $account->fresh()->password;
        $this->assertNotSame($seen, $fresh, 'the previous technician saw a secret that is void now');
        $this->reveal($workOrder, $secondToken)->assertOk()
            ->assertJsonPath('data.username', $account->username)
            ->assertJsonPath('data.password', $fresh);
    }

    public function test_replaying_the_reveal_key_after_reassignment_never_returns_the_secret(): void
    {
        [, $firstToken, $workOrder, $account] = $this->assignedInstallation();
        $key = (string) Str::uuid();
        $this->reveal($workOrder, $firstToken, $key)->assertOk()->assertJsonPath('data.password', $account->password);

        $this->assignTo($workOrder, $this->technicianLogin($this->company));

        $replay = $this->reveal($workOrder, $firstToken, $key);

        $replay->assertNotFound();
        $this->assertStringNotContainsString($account->password, $replay->getContent());
        $replay->assertHeaderMissing('Idempotent-Replayed');
    }

    public function test_only_the_assignee_and_only_their_company(): void
    {
        [, , $workOrder] = $this->assignedInstallation();
        $peer = $this->issueToken($this->technicianLogin($this->company), 'technician');
        $stranger = $this->issueToken($this->technicianLogin(), 'technician');

        $this->reveal($workOrder, $peer)->assertNotFound();
        $this->reveal($workOrder, $stranger)->assertNotFound();
        $this->assertSame(0, Activity::query()->where('description', 'secret_revealed')->count());
    }

    public function test_an_spk_of_another_type_has_nothing_to_reveal(): void
    {
        $technician = $this->technicianLogin($this->company);
        $maintenance = $this->installation(['status' => 'assigned', 'assigned_to' => $technician->id], type: 'maintenance');
        NetworkAccount::factory()->create(['company_id' => $this->company->id, 'subscription_id' => $this->subscription->id]);

        $this->reveal($maintenance, $this->issueToken($technician, 'technician'))->assertNotFound();
    }

    public function test_closed_spk_answers_409(): void
    {
        [, $token, $workOrder] = $this->assignedInstallation();

        foreach (['waiting_review', 'completed', 'cancelled', 'rejected'] as $status) {
            $workOrder->forceFill(['status' => $status])->saveQuietly();

            $this->reveal($workOrder, $token)
                ->assertStatus(409)
                ->assertJsonPath('code', 'work_order_closed')
                ->assertJsonPath('message', 'SPK ini sudah tidak aktif.');
        }

        $this->assertSame(0, Activity::query()->where('description', 'secret_revealed')->count());
    }

    public function test_the_login_is_shown_while_the_spk_is_in_progress(): void
    {
        [, $token, $workOrder] = $this->assignedInstallation();
        $workOrder->forceFill(['status' => 'in_progress'])->saveQuietly();

        $this->reveal($workOrder, $token)->assertOk();
    }

    public function test_missing_account_answers_422(): void
    {
        $nameless = $this->subscriptionIn($this->company, 'pending', null, $this->packageWithRadiusProfile($this->company, null));
        [, $token, $workOrder] = $this->assignedInstallation($nameless);
        $this->assertSame(0, NetworkAccount::query()->count(), 'the guard refused at assignment');

        $this->reveal($workOrder, $token)
            ->assertUnprocessable()
            ->assertJsonPath('code', 'credentials_not_ready')
            ->assertJsonPath('message', 'Akun PPPoE belum dibuat. Hubungi kantor.');
    }

    public function test_a_terminated_subscription_has_no_login_to_show(): void
    {
        [, $token, $workOrder] = $this->assignedInstallation();
        $this->subscription->forceFill(['status' => 'terminated'])->saveQuietly();

        $this->reveal($workOrder, $token)->assertUnprocessable()->assertJsonPath('code', 'credentials_not_ready');
    }

    public function test_rate_limited_after_five(): void
    {
        [, $token, $workOrder] = $this->assignedInstallation();
        $other = $this->issueToken($this->technicianLogin($this->company), 'technician');

        for ($call = 1; $call <= 5; $call++) {
            $this->reveal($workOrder, $token)->assertOk();
        }

        $this->reveal($workOrder, $token)->assertStatus(429)->assertJsonPath('code', 'too_many_requests');
        $this->assertNotSame(429, $this->reveal($workOrder, $other)->status(), 'the budget is per technician');
        $this->assertSame(5, Activity::query()->where('description', 'secret_revealed')->count());
    }

    public function test_secret_never_in_work_order_resource_or_list(): void
    {
        [, $token, $workOrder, $account] = $this->assignedInstallation();
        $secret = $account->password;

        $show = $this->api('GET', "technician/work-orders/{$workOrder->public_id}", token: $token)->assertOk();
        $show->assertJsonPath('data.network_account', ['username' => $account->username, 'sync_status' => 'synced', 'can_reveal' => true]);
        $this->assertStringNotContainsString($secret, $show->getContent());

        $enRoute = $this->api('POST', "technician/work-orders/{$workOrder->public_id}/en-route", token: $token)->assertOk();
        $this->assertStringNotContainsString($secret, $enRoute->getContent());

        $start = $this->api('POST', "technician/work-orders/{$workOrder->public_id}/start", token: $token)->assertOk();
        $this->assertStringNotContainsString($secret, $start->getContent());

        $queue = $this->api('GET', 'technician/my-work', token: $token)->assertOk();
        $this->assertStringNotContainsString($secret, $queue->getContent());
        $this->assertStringNotContainsString('network_account', $queue->getContent());
    }

    public function test_the_account_block_says_so_when_the_login_cannot_be_shown(): void
    {
        [, $token, $workOrder] = $this->assignedInstallation();
        $uri = "technician/work-orders/{$workOrder->public_id}";

        $workOrder->forceFill(['status' => 'waiting_review'])->saveQuietly();
        $this->api('GET', $uri, token: $token)->assertOk()->assertJsonPath('data.network_account.can_reveal', false);

        $this->subscription->forceFill(['status' => 'terminated'])->saveQuietly();
        $workOrder->forceFill(['status' => 'assigned'])->saveQuietly();
        $this->api('GET', $uri, token: $token)->assertOk()->assertJsonPath('data.network_account.can_reveal', false);
    }

    public function test_the_block_is_null_for_a_maintenance_spk_and_before_the_account_exists(): void
    {
        $technician = $this->technicianLogin($this->company);
        $token = $this->issueToken($technician, 'technician');
        $maintenance = $this->installation(['status' => 'assigned', 'assigned_to' => $technician->id], type: 'maintenance');
        $installation = $this->installation(['status' => 'assigned', 'assigned_to' => $technician->id]);
        $this->assertSame(0, NetworkAccount::query()->count());

        $this->api('GET', "technician/work-orders/{$maintenance->public_id}", token: $token)->assertOk()->assertJsonPath('data.network_account', null);
        $this->api('GET', "technician/work-orders/{$installation->public_id}", token: $token)->assertOk()->assertJsonPath('data.network_account', null);
    }

    public function test_a_failed_write_reads_as_error(): void
    {
        [, $token, $workOrder, $account] = $this->assignedInstallation();
        $account->forceFill(['sync_status' => 'failed'])->save();

        $block = $this->api('GET', "technician/work-orders/{$workOrder->public_id}", token: $token)->assertOk()->json('data.network_account');

        $this->assertSame(['can_reveal', 'sync_status', 'username'], collect($block)->keys()->sort()->values()->all());
        $this->assertSame('error', $block['sync_status']);
    }
}
