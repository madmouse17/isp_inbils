<?php

namespace App\Http\Controllers\Operations;

use App\Http\Controllers\Controller;
use App\Support\Observability\ReadinessChecker;
use Illuminate\Http\JsonResponse;

final class ReadinessController extends Controller
{
    public function __invoke(ReadinessChecker $checker): JsonResponse
    {
        $result = $checker->check();

        // Policy: 200 ready|degraded, 503 not_ready. No exception text ever.
        $code = $result['status'] === 'not_ready' ? 503 : 200;

        return response()->json($result, $code);
    }
}
