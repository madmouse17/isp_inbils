<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
