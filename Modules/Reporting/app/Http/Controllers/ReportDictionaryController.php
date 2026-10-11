<?php

namespace Modules\Reporting\Http\Controllers;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Modules\Reporting\Registry\MetricRegistry;

class ReportDictionaryController extends Controller
{
    /**
     * Read-only data dictionary. The content is static registry text: no company data and no queries.
     */
    public function __invoke(): InertiaResponse
    {
        return Inertia::render('Admin/Reports/Dictionary', [
            'reports' => MetricRegistry::dictionary(),
        ]);
    }
}
