<?php

namespace Modules\Reporting\Http\Controllers;

use App\Http\Controllers\Controller;
use Modules\Reporting\Exports\ReportExporter;
use Modules\Reporting\Http\Requests\ReportExportRequest;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportExportController extends Controller
{
    public function __invoke(ReportExportRequest $request): StreamedResponse
    {
        return ReportExporter::report($request);
    }
}
