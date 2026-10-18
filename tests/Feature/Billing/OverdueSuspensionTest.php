<?php

namespace Tests\Feature\Billing;

use App\Models\Core\Company;
use App\Models\User;
use App\Services\Core\SubscriptionService;
use App\Support\OptimisticLock;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Foundation\Console\Kernel as FoundationKernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Notifications\BillingScheduleFailedNotification;
use Modules\Billing\Services\BillingScheduleGateway;
use Modules\Billing\Services\OverdueSuspensionService;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;
use Tests\Traits\BuildsSubscriptionBilling;
use Tests\Traits\CreatesCompanyUser;

class OverdueSuspensionTest extends TestCase
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
        $this->enableAutoSuspend($this->company);
    }

    /** @param array<string, mixed> $overrides */
    private function enableAutoSuspend(Company $company, array $overrides = []): void
    {
        $company->update(['settings' => array_merge(['auto_suspend_enabled' => true, 'auto_suspend_overdue_days' => 7], $overrides)]);
    }

    private function runCommand(): void
    {
        Auth::logout();
        $this->artisan('billing:suspend-overdue')->assertSuccessful();
    }

    public function test_grace_boundary_n_days_is_safe_and_n_plus_one_is_suspended(): void
    {
        $onTheLine = $this->subscriptionIn($this->company);
        $this->invoiceFor($onTheLine, 7);
        $pastTheLine = $this->subscriptionIn($this->company);
        $this->invoiceFor($pastTheLine, 8);

        $this->runCommand();

        $this->assertSame('active', $this->freshSubscription($onTheLine)->status);
        $suspended = $this->freshSubscription($pastTheLine);
        $this->assertSame('suspended', $suspended->status);
        $this->assertSame('overdue', $suspended->suspension_source);
    }

    public function test_grace_days_follow_the_company_setting(): void
    {
        $this->enableAutoSuspend($this->company, ['auto_suspend_overdue_days' => 3]);
        $subscription = $this->subscriptionIn($this->company);
        $this->invoiceFor($subscription, 4);

        $this->runCommand();

        $this->assertSame('suspended', $this->freshSubscription($subscription)->status);
    }

    public function test_grace_days_default_to_seven_when_the_setting_is_missing(): void
    {
        $this->company->update(['settings' => ['auto_suspend_enabled' => true]]);
        $safe = $this->subscriptionIn($this->company);
        $this->invoiceFor($safe, 7);
        $late = $this->subscriptionIn($this->company);
        $this->invoiceFor($late, 8);

        $this->runCommand();

        $this->assertSame('active', $this->freshSubscription($safe)->status);
        $this->assertSame('suspended', $this->freshSubscription($late)->status);
    }

    public function test_a_partially_paid_invoice_past_the_grace_is_still_eligible(): void
    {
        $subscription = $this->subscriptionIn($this->company);
        $this->invoiceFor($subscription, 20, ['status' => 'partial', 'paid_amount' => 40000]);

        $this->runCommand();

        $this->assertSame('suspended', $this->freshSubscription($subscription)->status);
    }

    /** @return array<string, array{0: string, 1: int, 2: bool}> */
    public static function invoiceStates(): array
    {
        return [
            'sent and unpaid' => ['sent', 0, true],
            'overdue and unpaid' => ['overdue', 0, true],
            'partial' => ['partial', 50000, true],
            'draft' => ['draft', 0, false],
            'cancelled' => ['cancelled', 0, false],
            'paid in full' => ['paid', 100000, false],
            'sent but fully covered' => ['sent', 100000, false],
        ];
    }

    #[DataProvider('invoiceStates')]
    public function test_only_unpaid_sent_partial_or_overdue_invoices_count(string $status, int $paid, bool $suspended): void
    {
        $subscription = $this->subscriptionIn($this->company);
        $this->invoiceFor($subscription, 30, ['status' => $status, 'paid_amount' => $paid]);

        $this->runCommand();

        $this->assertSame($suspended ? 'suspended' : 'active', $this->freshSubscription($subscription)->status);
    }

    public function test_a_soft_deleted_invoice_does_not_count(): void
    {
        $subscription = $this->subscriptionIn($this->company);
        $this->invoiceFor($subscription, 30)->delete();

        $this->runCommand();

        $this->assertSame('active', $this->freshSubscription($subscription)->status);
    }

    public function test_invoices_of_another_subscription_of_the_same_customer_are_ignored(): void
    {
        $overdue = $this->subscriptionIn($this->company);
        $this->invoiceFor($overdue, 30);
        $sibling = $this->subscriptionIn($this->company);
        $sibling->forceFill(['customer_id' => $overdue->customer_id])->save();
        $this->invoiceFor($sibling, 1);

        $this->runCommand();

        $this->assertSame('suspended', $this->freshSubscription($overdue)->status);
        $this->assertSame('active', $this->freshSubscription($sibling)->status);
    }

    public function test_pending_suspended_and_terminated_subscriptions_are_untouched(): void
    {
        $pending = $this->subscriptionIn($this->company, 'pending');
        $suspendedByHand = $this->subscriptionIn($this->company, 'suspended', 'manual');
        $terminated = $this->subscriptionIn($this->company, 'terminated');
        foreach ([$pending, $suspendedByHand, $terminated] as $subscription) {
            $this->invoiceFor($subscription, 30);
        }

        $this->runCommand();

        $this->assertSame('pending', $this->freshSubscription($pending)->status);
        $this->assertSame('manual', $this->freshSubscription($suspendedByHand)->suspension_source);
        $this->assertSame('terminated', $this->freshSubscription($terminated)->status);
        $this->assertSame(0, Activity::query()->where('description', 'suspended')->count());
    }

    public function test_nothing_happens_while_automatic_isolir_is_off(): void
    {
        $subscription = $this->subscriptionIn($this->company);
        $this->invoiceFor($subscription, 30);

        foreach ([['auto_suspend_enabled' => false], ['auto_suspend_enabled' => '0']] as $off) {
            $this->enableAutoSuspend($this->company, $off);
            $this->runCommand();
            $this->assertSame('active', $this->freshSubscription($subscription)->status);
        }

        $this->company->update(['settings' => []]);
        $this->runCommand();
        $this->assertSame('active', $this->freshSubscription($subscription)->status);
    }

    public function test_dry_run_writes_nothing_and_lists_who_would_be_suspended(): void
    {
        $subscription = $this->subscriptionIn($this->company);
        $this->invoiceFor($subscription, 30);
        $clean = $this->subscriptionIn($this->company);
        Auth::logout();

        $this->artisan('billing:suspend-overdue', ['--dry-run' => true])
            ->expectsOutputToContain($subscription->code)
            ->doesntExpectOutputToContain($clean->code)
            ->assertSuccessful();

        $this->assertSame('active', $this->freshSubscription($subscription)->status);
        $this->assertNull($this->freshSubscription($subscription)->suspension_source);
        $this->assertSame(0, Activity::query()->where('description', 'suspended')->count());
    }

    public function test_a_real_run_audits_each_suspension_with_source_overdue_and_no_causer(): void
    {
        $first = $this->subscriptionIn($this->company);
        $this->invoiceFor($first, 30);
        $second = $this->subscriptionIn($this->company);
        $this->invoiceFor($second, 12);

        $this->runCommand();

        $entries = Activity::query()->where('log_name', 'service_subscription')->where('description', 'suspended')->get();
        $this->assertCount(2, $entries);
        foreach ($entries as $entry) {
            $this->assertNull($entry->causer_id);
            $this->assertSame('overdue', $entry->properties['source']);
            $this->assertSame($this->company->id, $entry->company_id);
            $this->assertStringContainsString('7', $entry->properties['reason']);
        }
        $this->assertEqualsCanonicalizing([$first->id, $second->id], $entries->pluck('subject_id')->all());
    }

    public function test_company_option_never_touches_another_company(): void
    {
        $other = Company::factory()->create(['is_active' => true]);
        $this->enableAutoSuspend($other);
        $mine = $this->subscriptionIn($this->company);
        $this->invoiceFor($mine, 30);
        $theirs = $this->subscriptionIn($other);
        $this->invoiceFor($theirs, 30);
        Auth::logout();

        $this->artisan('billing:suspend-overdue', ['--company' => $this->company->id])->assertSuccessful();

        $this->assertSame('suspended', $this->freshSubscription($mine)->status);
        $this->assertSame('active', $this->freshSubscription($theirs)->status);
    }

    public function test_a_run_without_company_covers_every_active_company_with_its_own_settings(): void
    {
        $other = Company::factory()->create(['is_active' => true]);
        $this->enableAutoSuspend($other, ['auto_suspend_overdue_days' => 30]);
        $inactive = Company::factory()->create(['is_active' => false]);
        $this->enableAutoSuspend($inactive);
        $mine = $this->subscriptionIn($this->company);
        $this->invoiceFor($mine, 10);
        $theirsWithinGrace = $this->subscriptionIn($other);
        $this->invoiceFor($theirsWithinGrace, 10);
        $theirsLate = $this->subscriptionIn($other);
        $this->invoiceFor($theirsLate, 31);
        $dormant = $this->subscriptionIn($inactive);
        $this->invoiceFor($dormant, 60);

        $this->runCommand();

        $this->assertSame('suspended', $this->freshSubscription($mine)->status);
        $this->assertSame('active', $this->freshSubscription($theirsWithinGrace)->status);
        $this->assertSame('suspended', $this->freshSubscription($theirsLate)->status);
        $this->assertSame('active', $this->freshSubscription($dormant)->status);
        $this->assertSame($other->id, Activity::query()->where('subject_id', $theirsLate->id)->where('description', 'suspended')->value('company_id'));
    }

    public function test_a_failure_notifies_the_admins_and_fails_the_command(): void
    {
        Notification::fake();
        $this->mock(BillingScheduleGateway::class, function ($mock) {
            $mock->shouldReceive('suspendOverdue')->once()->andThrow(new RuntimeException('forced suspend failure'));
        });
        // Seeding in setUp already built the command objects with the real gateway.
        /** @var FoundationKernel $kernel */
        $kernel = $this->app->make(ConsoleKernel::class);
        $kernel->setArtisan(null);

        $this->artisan('billing:suspend-overdue')->assertFailed();

        Notification::assertSentTo($this->admin, BillingScheduleFailedNotification::class, fn ($n) => $n->command === 'billing:suspend-overdue' && $n->companyId === $this->company->id);
    }

    public function test_the_isolir_locks_the_customer_before_its_subscription(): void
    {
        $subscription = $this->subscriptionIn($this->company);
        $this->invoiceFor($subscription, 30);

        DB::enableQueryLog();
        OverdueSuspensionService::suspendOverdue($this->company->id);
        $locks = collect(DB::getQueryLog())->pluck('query')->filter(fn (string $sql): bool => str_contains($sql, 'for update'))->values();

        $customerLock = $locks->search(fn (string $sql): bool => str_contains($sql, 'from `customers`'));
        $subscriptionLock = $locks->search(fn (string $sql): bool => str_contains($sql, 'from `service_subscriptions`'));
        $this->assertNotFalse($customerLock, 'the customer row is locked');
        $this->assertNotFalse($subscriptionLock, 'and so is the subscription row');
        $this->assertLessThan($subscriptionLock, $customerLock, 'lane lock order: the customer first, then its subscription');
        $this->assertSame('suspended', $this->freshSubscription($subscription)->status);
    }

    public function test_the_command_is_scheduled_daily_at_eight_without_overlapping(): void
    {
        $event = collect($this->app->make(Schedule::class)->events())
            ->first(fn (Event $event): bool => str_contains((string) $event->command, 'billing:suspend-overdue'));

        $this->assertNotNull($event, 'billing:suspend-overdue is not scheduled.');
        $this->assertSame('0 8 * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }

    public function test_suspend_route_stores_manual_by_default_and_overdue_when_asked(): void
    {
        $this->actingAs($this->admin);
        $byHand = $this->subscriptionIn($this->company);
        $fromReceivables = $this->subscriptionIn($this->company);

        $this->post(route('admin.subscriptions.suspend', $byHand), ['reason' => 'customer request'])->assertRedirect();
        $this->post(route('admin.subscriptions.suspend', $fromReceivables), ['reason' => 'unpaid', 'source' => 'overdue'])->assertRedirect();

        $this->assertSame('manual', $this->freshSubscription($byHand)->suspension_source);
        $this->assertSame('overdue', $this->freshSubscription($fromReceivables)->suspension_source);
    }

    public function test_suspend_route_rejects_an_unknown_source(): void
    {
        $this->actingAs($this->admin);
        $subscription = $this->subscriptionIn($this->company);

        $this->postJson(route('admin.subscriptions.suspend', $subscription), ['reason' => 'unpaid', 'source' => 'robot'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('source');

        $this->assertSame('active', $this->freshSubscription($subscription)->status);
    }

    public function test_reactivating_or_terminating_clears_the_source_and_suspending_audits_it(): void
    {
        $this->actingAs($this->admin);
        $subscription = $this->subscriptionIn($this->company);
        $this->post(route('admin.subscriptions.suspend', $subscription), ['reason' => 'unpaid', 'source' => 'overdue'])->assertRedirect();

        $audit = Activity::query()->where('description', 'suspended')->where('subject_id', $subscription->id)->firstOrFail();
        $this->assertSame('overdue', $audit->properties['source']);
        $this->assertSame($this->admin->id, $audit->causer_id);

        $this->post(route('admin.subscriptions.reactivate', $subscription))->assertRedirect();
        $this->assertNull($this->freshSubscription($subscription)->suspension_source);

        $this->post(route('admin.subscriptions.suspend', $subscription), ['reason' => 'again'])->assertRedirect();
        $this->assertSame('manual', $this->freshSubscription($subscription)->suspension_source);
        $this->post(route('admin.subscriptions.terminate', $subscription), ['reason' => 'moved'])->assertRedirect();
        $this->assertNull($this->freshSubscription($subscription)->suspension_source);
    }

    private function pay(Invoice $invoice, int $amount = 100000): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.invoices.payments.store', $invoice), ['amount' => $amount, 'method' => 'cash'])
            ->assertRedirect();
    }

    public function test_suspending_with_source_overdue_records_the_moment_and_manual_does_not(): void
    {
        $overdue = $this->subscriptionIn($this->company);
        $manual = $this->subscriptionIn($this->company);

        SubscriptionService::suspend($overdue, 'unpaid', 'overdue');
        SubscriptionService::suspend($manual, 'by hand');

        $this->assertTrue(now()->equalTo($this->freshSubscription($overdue)->suspended_at));
        $this->assertNull($this->freshSubscription($manual)->suspended_at);

        SubscriptionService::reactivate($overdue);
        $this->assertNull($this->freshSubscription($overdue)->suspended_at);
    }

    public function test_terminating_clears_the_suspension_moment(): void
    {
        $subscription = $this->subscriptionIn($this->company);
        SubscriptionService::suspend($subscription, 'unpaid', 'overdue');

        SubscriptionService::terminate($this->freshSubscription($subscription), 'moved');

        $this->assertNull($this->freshSubscription($subscription)->suspended_at);
    }

    public function test_an_early_isolir_stays_until_every_invoice_due_at_suspension_is_paid(): void
    {
        $subscription = $this->subscriptionIn($this->company);
        $insideGrace = $this->invoiceFor($subscription, 3);
        $pastGrace = $this->invoiceFor($subscription, 10);
        SubscriptionService::suspend($subscription, 'unpaid', 'overdue');

        $this->pay($pastGrace);
        $this->assertSame('suspended', $this->freshSubscription($subscription)->status);

        $this->pay($insideGrace);
        $this->assertSame('active', $this->freshSubscription($subscription)->status);
    }

    public function test_an_invoice_due_after_the_suspension_does_not_block_the_reconnection(): void
    {
        $subscription = $this->subscriptionIn($this->company);
        $due = $this->invoiceFor($subscription, 10);
        SubscriptionService::suspend($subscription, 'unpaid', 'overdue');
        $this->invoiceFor($subscription, -5);

        $this->pay($due);

        $this->assertSame('active', $this->freshSubscription($subscription)->status);
    }

    public function test_a_legacy_overdue_row_without_a_suspension_moment_keeps_the_grace_only_rule(): void
    {
        $subscription = $this->subscriptionIn($this->company, 'suspended', 'overdue');
        $this->invoiceFor($subscription, 3);
        $pastGrace = $this->invoiceFor($subscription, 10);

        $this->pay($pastGrace);

        $this->assertSame('active', $this->freshSubscription($subscription)->status);
    }

    public function test_settings_page_shows_the_three_isolir_keys_with_their_defaults(): void
    {
        $this->company->update(['settings' => []]);
        $this->actingAs($this->admin);

        $this->get(route('admin.company.settings.edit'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('settings.auto_suspend_enabled', false)
                ->where('settings.auto_suspend_overdue_days', 7)
                ->where('settings.auto_reactivate_on_payment', true));
    }

    public function test_settings_store_valid_isolir_values_and_reject_invalid_ones(): void
    {
        $this->actingAs($this->admin);

        $this->putJson(route('admin.company.settings.update'), ['settings' => [
            'auto_suspend_enabled' => true,
            'auto_suspend_overdue_days' => 14,
            'auto_reactivate_on_payment' => false,
        ], OptimisticLock::FIELD => $this->settingsVersion()])->assertRedirect();

        $settings = $this->company->fresh()->settings;
        $this->assertTrue($settings['auto_suspend_enabled']);
        $this->assertSame(14, $settings['auto_suspend_overdue_days']);
        $this->assertFalse($settings['auto_reactivate_on_payment']);

        foreach ([0, 61, 'abc', null] as $days) {
            $this->putJson(route('admin.company.settings.update'), ['settings' => ['auto_suspend_overdue_days' => $days], OptimisticLock::FIELD => $this->settingsVersion()])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('settings.auto_suspend_overdue_days');
        }
        foreach (['auto_suspend_enabled', 'auto_reactivate_on_payment'] as $key) {
            $this->putJson(route('admin.company.settings.update'), ['settings' => [$key => 'maybe'], OptimisticLock::FIELD => $this->settingsVersion()])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('settings.'.$key);
        }
        $this->assertSame(14, $this->company->fresh()->settings['auto_suspend_overdue_days']);
    }

    /** The version the settings form was rendered from. */
    private function settingsVersion(): string
    {
        return OptimisticLock::version($this->company->fresh());
    }
}
