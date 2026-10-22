<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\Tenants\CodeCheckRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Whether a company code is still free, for the "Perusahaan baru" form. It reads the same rule the form is checked by
 * (`unique:companies,code`), so an answer of "free" never turns into a refusal on submit for that reason. It tells an
 * operator nothing else about a company.
 */
class CompanyCodeCheckController extends Controller
{
    public function __invoke(CodeCheckRequest $request): JsonResponse
    {
        return response()->json([
            'available' => ! DB::table('companies')->where('code', $request->validated('code'))->exists(),
        ]);
    }
}
