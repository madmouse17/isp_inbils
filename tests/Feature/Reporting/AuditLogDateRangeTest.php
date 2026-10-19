<?php

namespace Tests\Feature\Reporting;

use App\Models\Core\Company;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;
use Tests\Traits\CreatesReportingUsers;

/**
 * The audit log table grows without bound, so its page and its export always have a period:
 * seven company days when none is given, 92 days at most.
 */
class AuditLogDateRangeTest extends TestCase
{
    use CreatesReportingUsers;
    use RefreshDatabase;

    private const TOO_LONG = 'Periode log audit paling lama 92 hari. Persempit periode.';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        app()['cache']->forget('spatie.permission.cache');

        $this->admin = $this->userWithRole('admin');
        Company::query()->whereKey($this->admin->company_id)->update(['timezone' => 'Asia/Jakarta']);
        $this->actingAs($this->admin);
    }

    public function test_the_page_defaults_to_the_last_seven_company_days(): void
    {
        // 18:30 UTC is already 01:30 of the next day in Jakarta: the company day decides, not the server day.
        $this->travelTo('2026-03-10 18:30:00', fn () => $this->get(route('admin.reports.audit-log'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('filters.date_from', '2026-03-05')->where('filters.date_to', null)));
    }

    public function test_date_to_alone_anchors_the_default_seven_days(): void
    {
        $this->get(route('admin.reports.audit-log', ['date_to' => '2026-01-10']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('filters.date_from', '2026-01-04')->where('filters.date_to', '2026-01-10'));

        $this->get(route('admin.reports.audit-log.export', ['date_to' => '2026-01-10']))->assertOk();
    }

    public function test_a_span_over_92_days_is_refused_on_the_page_and_the_export(): void
    {
        $span = ['date_from' => '2026-01-01', 'date_to' => '2026-04-03'];

        $this->from('/back')->get(route('admin.reports.audit-log', $span))
            ->assertRedirect('/back')
            ->assertSessionHasErrors(['date_from' => self::TOO_LONG]);

        $this->from('/back')->get(route('admin.reports.audit-log.export', $span))
            ->assertRedirect('/back')
            ->assertSessionHasErrors(['date_from' => self::TOO_LONG]);

        $this->getJson(route('admin.reports.audit-log', $span))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['date_from' => self::TOO_LONG]);
    }

    public function test_92_days_inclusive_is_accepted(): void
    {
        $span = ['date_from' => '2026-01-01', 'date_to' => '2026-04-02'];

        $this->get(route('admin.reports.audit-log', $span))->assertOk();
        $this->get(route('admin.reports.audit-log.export', $span))->assertOk();
    }

    public function test_an_inverted_range_keeps_its_own_message_only(): void
    {
        $this->from('/back')->get(route('admin.reports.audit-log', ['date_from' => '2026-04-03', 'date_to' => '2026-01-01']))
            ->assertSessionHasErrors(['date_to' => 'Tanggal akhir tidak boleh sebelum tanggal mulai.'])
            ->assertSessionDoesntHaveErrors('date_from');
    }

    public function test_other_reports_keep_open_ranges(): void
    {
        $this->get(route('admin.reports.business', ['date_from' => '2020-01-01', 'date_to' => '2026-01-31']))->assertOk();
        $this->get(route('admin.reports.business.export', ['date_to' => '2026-01-31']))->assertOk();
    }
}
