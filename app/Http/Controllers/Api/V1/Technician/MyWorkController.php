<?php

namespace App\Http\Controllers\Api\V1\Technician;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\MyWorkRequest;
use App\Http\Resources\MyWorkItemResource;
use App\Queries\Admin\MyWorkQuery;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class MyWorkController extends Controller
{
    public function __invoke(MyWorkRequest $request): AnonymousResourceCollection
    {
        $items = MyWorkQuery::filtered($request)
            ->paginate(MyWorkQuery::perPage($request))
            ->withQueryString();

        return MyWorkItemResource::collection($items)->additional([
            'meta' => ['summary' => MyWorkQuery::summary($request)],
        ]);
    }
}
