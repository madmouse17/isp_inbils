<?php

namespace Tests\Feature\Billing;

use App\Models\Core\Company;
use App\Models\Core\ServiceSubscription;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Foundation\Console\Kernel as FoundationKernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Notification;
use Mockery\MockInterface;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Notifications\BillingScheduleFailedNotification;
use Modules\Billing\Services\BillingScheduleGateway;
use RuntimeException;
use Tests\TestCase;
use Tests\Traits\BuildsSubscriptionBilling;
use Tests\Traits\CreatesCompanyUser;

/**
 * One failure costs only its own subscription or company, and only the admins of that company hear of it.
 */
class BillingScheduleIsolationTest extends TestCase
{
    use BuildsSubscriptionBilling;
    use CreatesCompanyUser;
    use RefreshDatabase;

    private Company $first;

    private Company $second;

    private User $firstAdmin;

    private User $secondAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::create(2026, 10, 15, 8));
        $this->firstAdmin = $this->createCompanyUser();
        $this->secondAdmin = $this->createCompanyUser();
        $this->first = $this->firstAdmin->company;
        $this->second = $this->secondAdmin->company;
    }

    /** The gateway raises for $company and does the real work for every other company. */
    private function gatewayFailingFor(Company $company): void
    {
        $this->mock(BillingScheduleGateway::class, function (MockInterface $mock) use ($company): void {
            $real = new BillingScheduleGateway();
            $refuse = function (?int $companyId) use ($company): void {
                if ($companyId === $company->id) {
                    throw new RuntimeException('forced failure for company '.$company->id);
                }
            };

            $mock->shouldReceive('generateForPeriod')->andReturnUsing(function (string $period, bool $dryRun = false, ?int $companyId = null) use ($real, $refuse): array {
                $refuse($companyId);

                return $real->generateForPeriod($period, $dryRun, $companyId);
            });
            $mock->shouldReceive('checkOverdue')->andReturnUsing(function (?int $companyId = null) use ($real, $refuse): void {
                $refuse($companyId);
                $real->checkOverdue($companyId);
            });
            $mock->shouldReceive('suspendOverdue')->andReturnUsing(function (int $companyId, bool $dryRun = false) use ($real, $refuse): array {
                $refuse($companyId);

                return $real->suspendOverdue($companyId, $dryRun);
            });
        });

        // Seeding in setUp already built the command objects with the real gateway.
        /** @var FoundationKernel $kernel */
        $kernel = $this->app->make(ConsoleKernel::class);
        $kernel->setArtisan(null);
    }

    private function enableAutoSuspend(Company $company): void
    {
        $company->update(['settings' => ['auto_suspend_enabled' => true, 'auto_suspend_overdue_days' => 7]]);
    }

    private function assertOnlyTold(User $admin, User $others, string $command, Company $company): void
    {
        Notification::assertSentTo($admin, BillingScheduleFailedNotification::class, fn (BillingScheduleFailedNotification $n): bool => $n->command === $command && $n->companyId === $company->id);
        Notification::assertNotSentTo($others, BillingScheduleFailedNotification::class);
    }

    public function test_a_failing_company_does_not_stop_the_next_one(): void
    {
        $this->subscriptionIn($this->first);
        $this->subscriptionIn($this->second);
        $this->gatewayFailingFor($this->first);
        Notification::fake();
        Exceptions::fake();

        $this->artisan('billing:generate', ['--period' => '2026-09'])->assertFailed();

        $this->assertSame(0, Invoice::withoutCompany()->where('company_id', $this->first->id)->count());
        $this->assertSame(1, Invoice::withoutCompany()->where('company_id', $this->second->id)->where('type', 'recurring')->count(), 'the company after the broken one is still billed');
        $this->assertOnlyTold($this->firstAdmin, $this->secondAdmin, 'billing:generate', $this->first);
        Notification::assertSentTimes(BillingScheduleFailedNotification::class, 1);
        Exceptions::assertReported(fn (RuntimeException $e): bool => $e->getMessage() === 'forced failure for company '.$this->first->id);
    }

    public function test_one_bad_subscription_does_not_stop_its_company(): void
    {
        $good = $this->subscriptionIn($this->first);
        $bad = $this->subscriptionIn($this->first);
        $alsoGood = $this->subscriptionIn($this->first);
        Invoice::creating(function (Invoice $invoice) use ($bad): void {
            if ($invoice->subscription_id === $bad->id) {
                throw new RuntimeException('one bad row');
            }
        });
        Notification::fake();
        Exceptions::fake();

        $this->artisan('billing:generate', ['--period' => '2026-09'])
            ->expectsOutputToContain('created: 2, skipped: 0, failed: 1')
            ->assertFailed();

        $this->assertEqualsCanonicalizing([$good->id, $alsoGood->id], Invoice::withoutCompany()->where('type', 'recurring')->pluck('subscription_id')->all());
        $this->assertOnlyTold($this->firstAdmin, $this->secondAdmin, 'billing:generate', $this->first);
        Exceptions::assertReported(fn (RuntimeException $e): bool => $e->getMessage() === 'one bad row');

        // The Generate dialog says how many were left out, and runs the others' periods as done.
        $this->actingAs($this->firstAdmin)
            ->post(route('admin.invoices.generate'), ['period' => '2026-09'])
            ->assertSessionHas('success', 'Tagihan 2026-09: 0 dibuat, 2 dilewati, 1 gagal.');
    }

    public function test_check_overdue_and_suspend_continue_past_a_failing_company(): void
    {
        $this->enableAutoSuspend($this->first);
        $this->enableAutoSuspend($this->second);
        $firstInvoice = $this->invoiceFor($this->subscriptionIn($this->first), 10);
        $secondSubscription = $this->subscriptionIn($this->second);
        $secondInvoice = $this->invoiceFor($secondSubscription, 10);
        $this->gatewayFailingFor($this->first);
        Notification::fake();
        Exceptions::fake();

        $this->artisan('billing:check-overdue')->assertFailed();

        $this->assertSame('sent', Invoice::withoutCompany()->findOrFail($firstInvoice->id)->status);
        $this->assertSame('overdue', Invoice::withoutCompany()->findOrFail($secondInvoice->id)->status, 'the company after the broken one is still checked');
        $this->assertOnlyTold($this->firstAdmin, $this->secondAdmin, 'billing:check-overdue', $this->first);

        $this->artisan('billing:suspend-overdue')->assertFailed();

        $this->assertSame('suspended', $this->freshSubscription($secondSubscription)->status, 'and still isolated');
        $this->assertOnlyTold($this->firstAdmin, $this->secondAdmin, 'billing:suspend-overdue', $this->first);
        Notification::assertSentTimes(BillingScheduleFailedNotification::class, 2);
    }

    public function test_one_failing_suspension_does_not_stop_the_others(): void
    {
        $this->enableAutoSuspend($this->first);
        [$one, $two, $three] = [$this->subscriptionIn($this->first), $this->subscriptionIn($this->first), $this->subscriptionIn($this->first)];

        foreach ([$one, $two, $three] as $subscription) {
            $this->invoiceFor($subscription, 10);
        }

        ServiceSubscription::updating(function (ServiceSubscription $subscription) use ($two): void {
            if ($subscription->id === $two->id) {
                throw new RuntimeException('one bad row');
            }
        });
        Notification::fake();
        Exceptions::fake();

        $this->artisan('billing:suspend-overdue')
            ->expectsOutputToContain($two->code)
            ->assertFailed();

        $this->assertSame('suspended', $this->freshSubscription($one)->status);
        $this->assertSame('active', $this->freshSubscription($two)->status);
        $this->assertSame('suspended', $this->freshSubscription($three)->status, 'the subscription after the broken one is still isolated');
        $this->assertOnlyTold($this->firstAdmin, $this->secondAdmin, 'billing:suspend-overdue', $this->first);
        Notification::assertSentTimes(BillingScheduleFailedNotification::class, 1);
        Exceptions::assertReported(fn (RuntimeException $e): bool => $e->getMessage() === 'one bad row');
        Exceptions::assertReported(fn (RuntimeException $e): bool => str_contains($e->getMessage(), $two->code) && ! str_contains($e->getMessage(), $one->code));
    }
}
