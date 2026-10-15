<?php

namespace Modules\Reporting\Http\Requests;

use Modules\Reporting\Queries\ReportFilters;
use Modules\Reporting\Registry\ReportType;
use Modules\Reporting\Services\TechnicianReportAccess;

/**
 * Base of the export and drill-down requests: the report is fixed by the route, and the filters
 * resolve exactly like the report screen resolves them.
 */
abstract class ReportScopedRequest extends ReportFilterRequest
{
    private ?ReportFilters $applied = null;

    abstract public function report(): ReportType;

    /**
     * The filters of an aggregate report with the actor's company. On the Technician report the
     * technician is the one the actor may select on screen: 403 for anyone else, 404 when none
     * is chosen.
     */
    public function filters(): ReportFilters
    {
        if ($this->applied !== null) {
            return $this->applied;
        }

        $technicianId = null;

        if ($this->report() === ReportType::Technician) {
            $actor = $this->user();
            TechnicianReportAccess::companyId($actor);
            $technicianId = TechnicianReportAccess::target($actor, $this->input('technician_id'));
            abort_if($technicianId === null, 404, 'Pilih teknisi terlebih dahulu.');
        }

        return $this->applied = ReportFilters::fromRequest($this, $technicianId);
    }
}
