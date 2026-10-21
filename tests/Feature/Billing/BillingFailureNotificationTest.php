<?php

namespace Tests\Feature\Billing;

use App\Models\Core\Company;
use App\Models\User;
use App\Services\Core\CompanyMembershipService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Modules\Billing\Notifications\BillingScheduleFailedNotification;
use Modules\Billing\Services\BillingScheduleGateway;
use RuntimeException;
use Tests\TestCase;

class BillingFailureNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_generate_command_notifies_on_failure(): void
    {
        Notification::fake();

        $company = Company::factory()->create();
        $admin = User::factory()->create(['company_id' => $company->id, 'is_active' => true]);
        $this->grantRole($admin, 'admin');
        $inactive = User::factory()->create(['company_id' => $company->id, 'is_active' => false]);
        $this->grantRole($inactive, 'admin');
        $nonAdmin = User::factory()->create(['company_id' => $company->id, 'is_active' => true]);

        $this->mock(BillingScheduleGateway::class, function ($mock) {
            $mock->shouldReceive('generateForPeriod')
                ->once()
                ->andThrow(new RuntimeException('forced generate failure'));
        });

        $this->artisan('billing:generate', ['--period' => '2026-06'])->assertFailed();

        Notification::assertSentTo($admin, BillingScheduleFailedNotification::class, function ($n) use ($company) {
            return $n->command === 'billing:generate'
                && $n->companyId === $company->id
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
        $this->grantRole($admin, 'admin');

        $this->mock(BillingScheduleGateway::class, function ($mock) {
            $mock->shouldReceive('checkOverdue')
                ->once()
                ->andThrow(new RuntimeException('forced overdue failure'));
        });

        $this->artisan('billing:check-overdue')->assertFailed();

        Notification::assertSentTo($admin, BillingScheduleFailedNotification::class, function ($n) use ($company) {
            return $n->command === 'billing:check-overdue' && $n->companyId === $company->id;
        });
    }

    public function test_recipients_are_the_active_administrators_of_that_one_company(): void
    {
        $company = Company::factory()->create();
        $otherCompany = Company::factory()->create();

        $admin = User::factory()->create(['company_id' => $company->id, 'is_active' => true]);
        $this->grantRole($admin, 'admin');
        $inactive = User::factory()->create(['company_id' => $company->id, 'is_active' => false]);
        $this->grantRole($inactive, 'admin');
        $manager = User::factory()->create(['company_id' => $company->id, 'is_active' => true]);
        $this->grantRole($manager, 'manager');
        User::factory()->create(['company_id' => $company->id, 'is_active' => true]);
        // A role of the company's own making counts when it administers: the recipients are the holders of roles.manage.
        $custom = $this->grantPermissions(User::factory()->create(['company_id' => $company->id, 'is_active' => true]), ['roles.manage']);
        // Homed in another company but a member here with Admin here: told as well.
        $member = User::factory()->create(['company_id' => $otherCompany->id, 'is_active' => true]);
        app(CompanyMembershipService::class)->grant($member, $company);
        $this->grantRole($member, 'admin', $company);
        $stranger = User::factory()->create(['company_id' => $otherCompany->id, 'is_active' => true]);
        $this->grantRole($stranger, 'admin');

        $this->assertEqualsCanonicalizing(
            [$admin->id, $custom->id, $member->id],
            BillingScheduleFailedNotification::recipients($company->id)->pluck('id')->all(),
        );
    }

    public function test_the_mail_is_indonesian_and_carries_the_command(): void
    {
        $mail = (new BillingScheduleFailedNotification('billing:generate', 7, 'job-1'))->toMail(new User());

        $this->assertSame('Jadwal penagihan gagal: billing:generate', $mail->subject);
        $this->assertContains('Perintah: billing:generate', $mail->introLines);
        $this->assertContains('ID perusahaan: 7', $mail->introLines);
        $this->assertContains('ID job/permintaan: job-1', $mail->introLines);
        $this->assertContains('Periksa log aplikasi untuk detailnya. Stack trace tidak disertakan.', $mail->introLines);
    }
}
