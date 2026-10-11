<?php

namespace Modules\Reporting\Http\Requests;

/** The file of the consolidated report: `report.export` on top of the report's own capability. */
class ConsolidatedReportExportRequest extends ConsolidatedReportRequest
{
    public function authorize(): bool
    {
        return parent::authorize() && $this->user()->can('report.export');
    }
}
