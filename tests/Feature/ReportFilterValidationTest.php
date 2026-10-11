<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Validator;
use Modules\Reporting\Http\Requests\ReportFilterRequest;
use Tests\TestCase;
use Tests\Traits\CreatesCompanyUser;

class ReportFilterValidationTest extends TestCase
{
    use CreatesCompanyUser;
    use RefreshDatabase;

    public function test_invalid_date_returns_422(): void
    {
        $validator = Validator::make([
            'date_from' => 'not-a-date',
            'date_to' => '2026-01-01',
        ], (new ReportFilterRequest())->rules());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('date_from', $validator->errors()->messages());
    }

    public function test_optional_dates_are_accepted(): void
    {
        $validator = Validator::make([
            'date_from' => '2026-01-01',
        ], (new ReportFilterRequest())->rules());

        $this->assertTrue($validator->passes());

        $request = ReportFilterRequest::create('/admin/reports/business', 'GET', ['date_to' => '2026-01-31']);
        $this->assertSame('2026-01-31 23:59:59', $request->dateTo());
    }

    public function test_unauthorized_users_remain_denied(): void
    {
        $request = ReportFilterRequest::create('/admin/reports/business', 'GET');
        $request->setUserResolver(fn () => new class()
        {
            public function can(string $permission): bool
            {
                return false;
            }
        });

        $this->assertFalse($request->authorize());
    }

    public function test_invalid_date_filters_return_422_from_report_endpoint(): void
    {
        $this->actingAs($this->createCompanyUser());

        $this->getJson(route('admin.reports.business', ['date_from' => 'not-a-date']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('date_from');

        $this->getJson(route('admin.reports.business', ['date_to' => 'not-a-date']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('date_to');
    }

    public function test_omitted_and_partial_date_filters_are_accepted_by_report_endpoint(): void
    {
        $this->actingAs($this->createCompanyUser());

        $this->get(route('admin.reports.business'))->assertOk();
        $this->get(route('admin.reports.business', ['date_from' => '2026-01-01']))->assertOk();
        $this->get(route('admin.reports.business', ['date_to' => '2026-01-31']))->assertOk();
    }

    public function test_authorize_follows_the_matched_route_name(): void
    {
        $user = new class()
        {
            public function can(string $permission): bool
            {
                return $permission === 'report.sla.view';
            }
        };

        $this->assertTrue($this->filterRequestOnRoute('admin.reports.sla', $user)->authorize());
        $this->assertFalse($this->filterRequestOnRoute('admin.reports.business', $user)->authorize());
        $this->assertFalse($this->filterRequestOnRoute('admin.reports.audit-log', $user)->authorize());
    }

    public function test_requests_without_a_known_report_route_are_denied_even_for_an_all_powerful_user(): void
    {
        $superUser = new class()
        {
            public function can(string $permission): bool
            {
                return true;
            }
        };

        $unmatched = ReportFilterRequest::create('/admin/reports/sla', 'GET');
        $unmatched->setUserResolver(fn () => $superUser);
        $this->assertFalse($unmatched->authorize());

        $this->assertFalse($this->filterRequestOnRoute('admin.reports.unknown', $superUser)->authorize());
        $this->assertFalse($this->filterRequestOnRoute('admin.reports.index', $superUser)->authorize());
    }

    private function filterRequestOnRoute(string $routeName, object $user): ReportFilterRequest
    {
        $route = (new Route('GET', '/admin/reports/sla', []))->name($routeName);
        $request = ReportFilterRequest::create('/admin/reports/sla', 'GET');
        $request->setRouteResolver(fn () => $route);
        $request->setUserResolver(fn () => $user);

        return $request;
    }
}
