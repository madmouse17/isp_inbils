<?php

namespace Modules\Reporting\Http\Controllers;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Modules\Reporting\Drill\DrillList;
use Modules\Reporting\Exports\ReportExporter;
use Modules\Reporting\Http\Requests\ReportDrillExportRequest;
use Modules\Reporting\Http\Requests\ReportDrillRequest;
use Modules\Reporting\Registry\MetricRegistry;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportDrillController extends Controller
{
    public function show(ReportDrillRequest $request): InertiaResponse
    {
        $report = $request->report();
        $source = $request->source();
        $filters = $request->filters();
        $actor = $request->user();

        $list = new DrillList($source, $filters, $actor);
        $tieOut = $list->tieOut($filters->payload($report));
        $applied = [...$filters->query($report), ...($filters->group === null ? [] : ['group' => $filters->group])];
        $sorting = array_filter(['sort_by' => $request->sort(), 'sort_dir' => $request->direction()]);

        return Inertia::render('Admin/Reports/Drill', [
            'report' => [
                'key' => $report->value,
                'title' => $report->title(),
                'href' => route($report->routeName(), $filters->query($report), false),
            ],
            'source' => [
                'key' => $source->key,
                'label' => $source->label,
                'group' => $filters->group,
                'kind' => $source->kind->value,
                'noun' => $source->kind->noun(),
                'definition' => MetricRegistry::meta($report)['metrics'][$source->metric()] ?? null,
            ],
            'columns' => $source->kind->columns(),
            'rows' => $list->paginate($request->sort(), $request->direction(), $request->perPage()),
            'tie_out' => $tieOut,
            'reconciliation' => $list->reconciliation($tieOut),
            'filters' => [...$applied, ...$sorting, 'per_page' => $request->perPage()],
            'export' => $actor->can('report.export')
                ? ['url' => route($report->drillRouteName().'.export', ['metric' => $source->key], false), 'params' => [...$applied, ...$sorting]]
                : null,
        ]);
    }

    public function export(ReportDrillExportRequest $request): StreamedResponse
    {
        return ReportExporter::drill($request);
    }
}
