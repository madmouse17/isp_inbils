<?php

namespace Tests\Feature;

use App\Models\Core\Company;
use App\Models\Core\ServiceSubscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;
use Tests\Traits\BuildsSubscriptionBilling;
use Tests\Traits\CreatesCompanyUser;

class SubscriptionBlockTest extends TestCase
{
    use BuildsSubscriptionBilling;
    use CreatesCompanyUser;
    use RefreshDatabase;

    private User $admin;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::create(2026, 10, 15, 8));
        $this->admin = $this->createCompanyUser();
        $this->company = $this->admin->company;
        $this->company->update(['settings' => ['auto_suspend_enabled' => true, 'auto_suspend_overdue_days' => 7]]);
    }

    private function block(ServiceSubscription $subscription, string $reason = 'abuse report'): TestResponse
    {
        return $this->actingAs($this->admin)
            ->post(route('admin.subscriptions.block', $subscription), ['reason' => $reason]);
    }

    private function unblock(ServiceSubscription $subscription, ?string $note = null): TestResponse
    {
        return $this->actingAs($this->admin)
            ->post(route('admin.subscriptions.unblock', $subscription), ['reason' => $note]);
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create([
            'company_id' => $this->company->id,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
        $user->assignRole($role);

        return $user;
    }

    public function test_block_and_unblock_record_state_and_audit(): void
    {
        $subscription = $this->subscriptionIn($this->company);

        $this->block($subscription, 'abuse report')->assertRedirect()->assertSessionHas('success');

        $blocked = $this->freshSubscription($subscription);
        $this->assertTrue($blocked->blocked_at->equalTo(now()));
        $this->assertSame($this->admin->id, $blocked->blocked_by);
        $this->assertSame('abuse report', $blocked->block_reason);
        $this->assertSame('active', $blocked->status);

        $audit = Activity::query()->where('description', 'blocked')->where('subject_id', $subscription->id)->sole();
        $this->assertSame('service_subscription', $audit->log_name);
        $this->assertSame($this->admin->id, $audit->causer_id);
        $this->assertSame('abuse report', $audit->properties['reason']);
        $this->assertEqualsCanonicalizing(['code', 'company_id', 'reason'], $audit->properties->keys()->all());

        $this->unblock($subscription, 'resolved')->assertRedirect()->assertSessionHas('success');

        $cleared = $this->freshSubscription($subscription);
        $this->assertNull($cleared->blocked_at);
        $this->assertNull($cleared->blocked_by);
        $this->assertNull($cleared->block_reason);

        $audit = Activity::query()->where('description', 'unblocked')->where('subject_id', $subscription->id)->sole();
        $this->assertSame($this->admin->id, $audit->causer_id);
        $this->assertSame('resolved', $audit->properties['note']);
        $this->assertEqualsCanonicalizing(['code', 'company_id', 'note'], $audit->properties->keys()->all());
    }

    public function test_block_works_on_pending_active_and_suspended_and_leaves_the_status_alone(): void
    {
        foreach (['pending', 'active', 'suspended'] as $status) {
            $subscription = $this->subscriptionIn($this->company, $status);

            $this->block($subscription)->assertRedirect();

            $fresh = $this->freshSubscription($subscription);
            $this->assertNotNull($fresh->blocked_at, $status);
            $this->assertSame($status, $fresh->status);
        }
    }

    public function test_block_requires_a_reason_of_at_most_500_characters(): void
    {
        $subscription = $this->subscriptionIn($this->company);

        $this->actingAs($this->admin)->post(route('admin.subscriptions.block', $subscription), [])
            ->assertSessionHasErrors('reason');
        $this->block($subscription, str_repeat('x', 501))->assertSessionHasErrors('reason');
        $this->assertNull($this->freshSubscription($subscription)->blocked_at);
    }

    public function test_block_is_rejected_for_a_terminated_or_already_blocked_subscription(): void
    {
        $terminated = $this->subscriptionIn($this->company, 'terminated');
        $this->block($terminated)->assertStatus(422);
        $this->assertNull($this->freshSubscription($terminated)->blocked_at);

        $subscription = $this->subscriptionIn($this->company);
        $this->block($subscription)->assertRedirect();
        $this->block($subscription, 'again')->assertStatus(422);
        $this->assertSame('abuse report', $this->freshSubscription($subscription)->block_reason);
    }

    public function test_unblock_is_rejected_when_not_blocked_and_the_note_is_capped(): void
    {
        $subscription = $this->subscriptionIn($this->company);

        $this->unblock($subscription)->assertStatus(422);

        $this->block($subscription)->assertRedirect();
        $this->unblock($subscription, str_repeat('x', 501))->assertSessionHasErrors('reason');
        $this->assertNotNull($this->freshSubscription($subscription)->blocked_at);
    }

    public function test_block_and_unblock_need_the_block_permission(): void
    {
        $subscription = $this->subscriptionIn($this->company);
        $staff = $this->userWithRole('staff');

        $this->actingAs($staff)->post(route('admin.subscriptions.block', $subscription), ['reason' => 'x'])->assertForbidden();
        $this->assertNull($this->freshSubscription($subscription)->blocked_at);

        $this->block($subscription)->assertRedirect();
        $this->actingAs($staff)->post(route('admin.subscriptions.unblock', $subscription))->assertForbidden();
        $this->assertNotNull($this->freshSubscription($subscription)->blocked_at);
    }

    public function test_a_manager_may_block(): void
    {
        $subscription = $this->subscriptionIn($this->company);

        $this->actingAs($this->userWithRole('manager'))
            ->post(route('admin.subscriptions.block', $subscription), ['reason' => 'fraud'])
            ->assertRedirect();

        $this->assertNotNull($this->freshSubscription($subscription)->blocked_at);
    }

    public function test_another_companys_subscription_is_not_found(): void
    {
        $other = Company::factory()->create(['is_active' => true]);
        $foreign = $this->subscriptionIn($other);

        $this->block($foreign)->assertNotFound();
        $this->unblock($foreign)->assertNotFound();
        $this->assertNull($this->freshSubscription($foreign)->blocked_at);
    }

    public function test_terminate_clears_the_block(): void
    {
        $subscription = $this->subscriptionIn($this->company);
        $this->block($subscription)->assertRedirect();

        $this->actingAs($this->admin)
            ->post(route('admin.subscriptions.terminate', $subscription), ['reason' => 'moved away'])
            ->assertRedirect();

        $fresh = $this->freshSubscription($subscription);
        $this->assertSame('terminated', $fresh->status);
        $this->assertNull($fresh->blocked_at);
        $this->assertNull($fresh->blocked_by);
        $this->assertNull($fresh->block_reason);
    }

    public function test_overdue_suspension_still_suspends_a_blocked_active_subscription(): void
    {
        $subscription = $this->subscriptionIn($this->company);
        $this->invoiceFor($subscription, 10);
        $this->block($subscription)->assertRedirect();

        Auth::logout();
        $this->artisan('billing:suspend-overdue')->assertSuccessful();

        $fresh = $this->freshSubscription($subscription);
        $this->assertSame('suspended', $fresh->status);
        $this->assertSame('overdue', $fresh->suspension_source);
        $this->assertNotNull($fresh->blocked_at);
    }

    public function test_a_clearing_payment_reactivates_the_status_and_keeps_the_block(): void
    {
        $subscription = $this->subscriptionIn($this->company, 'suspended', 'overdue');
        $subscription->forceFill(['suspended_at' => now()->subDays(3)])->save();
        $invoice = $this->invoiceFor($subscription, 10);
        $this->block($subscription)->assertRedirect();

        $this->actingAs($this->admin)
            ->post(route('admin.invoices.payments.store', $invoice), ['amount' => 100000, 'method' => 'cash'])
            ->assertRedirect();

        $fresh = $this->freshSubscription($subscription);
        $this->assertSame('active', $fresh->status);
        $this->assertNotNull($fresh->blocked_at);
        $this->assertSame('abuse report', $fresh->block_reason);
    }

    public function test_unblocking_a_suspended_subscription_leaves_it_suspended(): void
    {
        $subscription = $this->subscriptionIn($this->company, 'suspended', 'manual');
        $this->block($subscription)->assertRedirect();

        $this->unblock($subscription)->assertRedirect();

        $fresh = $this->freshSubscription($subscription);
        $this->assertSame('suspended', $fresh->status);
        $this->assertNull($fresh->blocked_at);
    }

    public function test_reactivate_never_clears_a_block(): void
    {
        $subscription = $this->subscriptionIn($this->company, 'suspended', 'manual');
        $this->block($subscription)->assertRedirect();

        $this->actingAs($this->admin)
            ->post(route('admin.subscriptions.reactivate', $subscription))
            ->assertRedirect();

        $fresh = $this->freshSubscription($subscription);
        $this->assertSame('active', $fresh->status);
        $this->assertNotNull($fresh->blocked_at);
    }

    public function test_subscription_show_carries_the_block_props(): void
    {
        $subscription = $this->subscriptionIn($this->company);
        $this->block($subscription, 'abuse report')->assertRedirect();

        $this->actingAs($this->admin)
            ->get(route('admin.subscriptions.show', $subscription))
            ->assertInertia(fn (Assert $page) => $page
                ->where('subscription.data.blocked_at', fn ($value) => $value !== null)
                ->where('subscription.data.block_reason', 'abuse report')
                ->where('subscription.data.blocked_by', ['id' => $this->admin->id, 'name' => $this->admin->name])
                ->where('can.block', true));

        $this->actingAs($this->userWithRole('staff'))
            ->get(route('admin.subscriptions.show', $subscription))
            ->assertInertia(fn (Assert $page) => $page->where('can.block', false));
    }

    public function test_receivables_rows_carry_blocked_at_and_can_block(): void
    {
        $subscription = $this->subscriptionIn($this->company);
        $this->invoiceFor($subscription, 3);
        $this->block($subscription)->assertRedirect();

        $this->actingAs($this->admin)
            ->get(route('admin.billing.receivables'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('can.block', true)
                ->where('rows.data.0.subscriptions.0.blocked_at', fn ($value) => $value !== null));
    }
}
