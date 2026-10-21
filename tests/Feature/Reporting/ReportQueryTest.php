<?php

namespace Tests\Feature\Reporting;

use App\Models\Core\Company;
use App\Models\Core\EmployeeProfile;
use App\Models\Core\ServiceSubscription;
use App\Models\User;
use App\Services\Core\CompanyService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Reporting\Queries\BusinessMetricsQuery;
use Modules\Reporting\Queries\TechnicianPerformanceQuery;
use Modules\SPK\Database\Factories\WorkOrderFactory;
use Tests\TestCase;
use Tests\Traits\CreatesCompanyUser;

class ReportQueryTest extends TestCase
{
    use CreatesCompanyUser;
    use RefreshDatabase;

    public function test_business_metrics_includes_rows_from_the_last_requested_day(): void
    {
        $admin = $this->createCompanyUser();
        Company::query()->whereKey($admin->company_id)->update(['timezone' => 'UTC']);
        CompanyService::resetCache();
        $this->actingAs($admin);

        foreach (['2026-01-31 00:01:00', '2026-01-31 12:00:00', '2026-01-31 23:59:59'] as $createdAt) {
            ServiceSubscription::factory()->create([
                'status' => 'active',
                'mrc_amount' => 125000,
                'created_at' => $createdAt,
            ]);
        }

        ServiceSubscription::factory()->create([
            'status' => 'active',
            'mrc_amount' => 125000,
            'created_at' => '2026-02-01 00:00:00',
        ]);

        $metrics = BusinessMetricsQuery::execute('2026-01-01', '2026-01-31');

        $this->assertSame(3, $metrics['new_subscriptions']);
    }

    public function test_the_period_is_read_as_calendar_days_of_the_company_timezone(): void
    {
        $admin = $this->createCompanyUser();   // Asia/Jakarta, UTC+7; timestamps are stored in UTC
        $this->assertSame('Asia/Jakarta', Company::query()->whereKey($admin->company_id)->value('timezone'));
        $this->actingAs($admin);

        $stamps = [
            '2026-10-13 16:30:00',   // 23:30 WIB on 13 October: inside date_to=2026-10-13
            '2026-10-13 16:59:59',   // the last second of 13 October in Jakarta
            '2026-10-13 17:00:00',   // 00:00:00 WIB on 14 October
            '2026-10-12 16:59:59',   // 23:59:59 WIB on 12 October
            '2026-10-12 17:00:00',   // 00:00:00 WIB on 13 October
        ];
        foreach ($stamps as $createdAt) {
            ServiceSubscription::factory()->create(['status' => 'active', 'mrc_amount' => 1000, 'created_at' => $createdAt]);
        }

        // 13 October in Jakarta = 2026-10-12 17:00:00 .. 2026-10-13 16:59:59 UTC.
        $this->assertSame(3, BusinessMetricsQuery::execute('2026-10-13', '2026-10-13')['new_subscriptions']);
        // To alone: everything up to the end of 13 October in Jakarta.
        $this->assertSame(4, BusinessMetricsQuery::execute(null, '2026-10-13')['new_subscriptions']);
        // From alone: from the first second of 13 October in Jakarta.
        $this->assertSame(4, BusinessMetricsQuery::execute('2026-10-13', null)['new_subscriptions']);
    }

    public function test_technician_metrics_keep_completed_count_when_averaging_started_work_orders(): void
    {
        $admin = $this->createCompanyUser();
        $this->actingAs($admin);
        $technician = User::factory()->create([
            'company_id' => $admin->company_id,
            'is_active' => true,
        ]);
        $this->grantRole($technician, 'technician');
        EmployeeProfile::factory()->create([
            'company_id' => $admin->company_id,
            'user_id' => $technician->id,
            'status' => 'active',
        ]);

        Carbon::setTestNow(Carbon::parse('2026-01-15 09:00:00'));

        WorkOrderFactory::new()->create([
            'assigned_to' => $technician->id,
            'created_by' => $admin->id,
            'status' => 'completed',
            'started_at' => null,
            'completed_at' => Carbon::parse('2026-01-15 09:30:00'),
        ]);

        WorkOrderFactory::new()->create([
            'assigned_to' => $technician->id,
            'created_by' => $admin->id,
            'status' => 'completed',
            'started_at' => Carbon::parse('2026-01-15 08:00:00'),
            'completed_at' => Carbon::parse('2026-01-15 08:20:00'),
        ]);

        Carbon::setTestNow();

        $metrics = TechnicianPerformanceQuery::execute($technician->id, '2026-01-01', '2026-01-31');

        $this->assertSame(2, $metrics['spk_completed']);
        $this->assertEquals(20, $metrics['avg_spk_minutes']);
    }

    public function test_business_metrics_maps_missing_statuses_to_zero(): void
    {
        $admin = $this->createCompanyUser();
        $this->actingAs($admin);

        ServiceSubscription::factory()->create([
            'status' => 'active',
            'mrc_amount' => 100000,
        ]);

        ServiceSubscription::factory()->create([
            'status' => 'suspended',
            'mrc_amount' => 150000,
        ]);

        $metrics = BusinessMetricsQuery::execute();

        $this->assertSame(1, $metrics['active_subscriptions']);
        $this->assertSame(1, $metrics['suspended_subscriptions']);
        $this->assertSame(0, $metrics['terminated_subscriptions']);
    }
}
