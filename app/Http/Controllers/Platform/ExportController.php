<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\Exports\ExportIndexRequest;
use App\Http\Requests\Platform\Exports\StoreTenantExportRequest;
use App\Models\Core\Company;
use App\Models\Platform\TenantExport;
use App\Models\PlatformAdmin;
use App\Services\Platform\TenantExportService;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExportController extends Controller
{
    public function store(StoreTenantExportRequest $request, Company $company, TenantExportService $exports): RedirectResponse
    {
        /** @var PlatformAdmin $actor */
        $actor = $request->user('platform');
        $exports->request($actor, $company);

        return redirect()->route('platform.companies.show', $company)->with('success', 'Ekspor data dijadwalkan.');
    }

    public function index(ExportIndexRequest $request, TenantExportService $exports): Response
    {
        $filters = $request->filters();

        return Inertia::render('Platform/Exports/Index', [
            'exports' => JsonResource::collection($exports->paginate($filters)),
            'filters' => $filters,
        ]);
    }

    public function download(Request $request, TenantExport $tenantExport, TenantExportService $exports): StreamedResponse
    {
        abort_unless($exports->isDownloadable($tenantExport), 404);

        /** @var PlatformAdmin $actor */
        $actor = $request->user('platform');

        if (! $exports->fileExists($tenantExport)) {
            $exports->expire($tenantExport, 'file_missing', $actor);
            abort(404);
        }

        $exports->recordDownload($tenantExport, $actor);
        $code = Str::lower((string) $tenantExport->company()->value('code'));

        /** @var FilesystemAdapter $disk */
        $disk = Storage::disk('local');

        return $disk->download(
            (string) $tenantExport->file_path,
            $code.'-tenant-export.zip',
            [
                'Content-Type' => 'application/zip',
                'Cache-Control' => 'private, no-store',
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }
}
