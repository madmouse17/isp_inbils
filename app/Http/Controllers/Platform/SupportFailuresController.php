<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\Support\FailedListRequest;
use App\Models\Core\Company;
use App\Services\Platform\SupportToolsService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;

class SupportFailuresController extends Controller
{
    public function jobs(FailedListRequest $request, Company $company, SupportToolsService $support): AnonymousResourceCollection
    {
        return JsonResource::collection($support->failedJobs($company, $request->page(), $request->perPage()))
            ->additional(['window_days' => SupportToolsService::WINDOW_DAYS]);
    }

    public function notifications(FailedListRequest $request, Company $company, SupportToolsService $support): AnonymousResourceCollection
    {
        return JsonResource::collection($support->failedNotifications($company, $request->page(), $request->perPage()))
            ->additional(['window_days' => SupportToolsService::WINDOW_DAYS]);
    }
}
