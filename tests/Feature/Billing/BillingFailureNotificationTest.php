<?php

namespace Tests\Feature\Billing;

use App\Models\Core\Company;
use App\Models\User;
use Illuminate\Support\Facades\Notification;
use Modules\Billing\Notifications\BillingScheduleFailedNotification;
use Modules\Billing\Services\BillingScheduleGateway;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class BillingFailureNotificationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    }

    public function test_generate_command_notifies_on_failure(): void
    {
        Notification::fake();

        $company = Company::factory()->create();
        $admin = User::factory()->create(['company_id' => $company->id, 'is_active' => true]);
        $admin->assignRole('admin');
        $inactive = User::factory()->create(['company_id' => $company->id, 'is_active' => false]);
        $inactive->assignRole('admin');
        $nonAdmin = User::factory()->create(['company_id' => $company->id, 'is_active' => true]);

        $this->mock(BillingScheduleGateway::class, function ($mock) {
            $mock->shouldReceive('generateForPeriod')
                ->once()
                ->andThrow(new RuntimeException('forced generate failure'));
        });

        $this->artisan('billing:generate', ['--period' => '2026-06'])->assertFailed();

        Notification::assertSentTo($admin, BillingScheduleFailedNotification::class, function ($n) {
            return $n->command === 'billing:generate'
                && $n->companyId === null
                && $n->jobId === null;
        });
        Notification::assertNotSentTo($inactive, BillingScheduleFailedNotification::class);
        Notification::assertNotSentTo($nonAdmin, BillingScheduleFailedNotification::class);
    }

    public function test_overdue_command_notifies_on_failure(): void
    {
        Notification::fake();

        $company = Company::factory()->create();
        $admin = User::factory()->create(['company_id' => $company->id, 'is_active' => true]);
        $admin->assignRole('admin');

        $this->mock(BillingScheduleGateway::class, function ($mock) {
            $mock->shouldReceive('checkOverdue')
                ->once()
                ->andThrow(new RuntimeException('forced overdue failure'));
        });

        $this->artisan('billing:check-overdue')->assertFailed();

        Notification::assertSentTo($admin, BillingScheduleFailedNotification::class, function ($n) {
            return $n->command === 'billing:check-overdue';
        });
    }
}
