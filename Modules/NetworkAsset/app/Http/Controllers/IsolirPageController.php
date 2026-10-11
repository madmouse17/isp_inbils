<?php

namespace Modules\NetworkAsset\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Core\Company;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * The page isolated subscribers are redirected to. Public: only the company's own public contact fields and
 * the login link, never anything about a subscriber or a session. An inactive company answers like an unknown one.
 */
class IsolirPageController extends Controller
{
    public function __invoke(Company $company): InertiaResponse
    {
        abort_unless($company->is_active, 404);

        return Inertia::render('Isolir/Show', [
            'company' => [
                'name' => $company->name,
                'phone' => $company->phone,
                'email' => $company->email,
                'website' => $company->website,
            ],
            'loginUrl' => route('login'),
        ]);
    }
}
