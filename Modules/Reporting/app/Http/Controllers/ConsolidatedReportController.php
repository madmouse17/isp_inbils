<?php

namespace Modules\Reporting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Core\Company;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Modules\Reporting\Exceptions\ConsolidationRefusedException;
use Modules\Reporting\Exports\ReportExporter;
use Modules\Reporting\Http\Requests\ConsolidatedReportExportRequest;
use Modules\Reporting\Http\Requests\ConsolidatedReportRequest;
use Modules\Reporting\Queries\ConsolidatedBusinessQuery;
use Modules\Reporting\Registry\MetricRegistry;
use Modules\Reporting\Registry\ReportType;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ConsolidatedReportController extends Controller
{
    public function index(ConsolidatedReportRequest $request): InertiaResponse
    {
        $head = $request->head();
        $companies = $request->companies();

        try {
            $report = ConsolidatedBusinessQuery::execute($companies, $request->dateFrom(), $request->dateTo());
            $refusal = null;
        } catch (ConsolidationRefusedException $e) {
            $report = null;
            $refusal = ['code' => 'currency_mismatch', 'message' => $e->getMessage(), 'currencies' => $e->currencies];
        }

        return Inertia::render('Admin/Reports/Consolidated', [
            'context' => [
                'mode' => 'consolidated',
                'head' => $head->only(['id', 'name', 'code', 'currency']),
                'currency' => $report['currency'] ?? null,
                'companies' => $companies
                    ->map(fn (Company $company): array => $company->only(['id', 'name', 'code', 'currency', 'timezone']))
                    ->all(),
            ],
            'data' => $report === null ? null : [
                'rows' => $report['rows'],
                'total' => $report['total'],
                'sla' => $report['sla'],
            ],
            'refusal' => $refusal,
            'filters' => $request->only(['date_from', 'date_to']),
            'meta' => MetricRegistry::meta(ReportType::Business),
            'can' => ['export' => $request->user()->can('report.export')],
        ]);
    }

    public function export(ConsolidatedReportExportRequest $request): StreamedResponse
    {
        return ReportExporter::consolidated($request);
    }
}
