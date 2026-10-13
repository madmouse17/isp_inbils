<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SwitchCompanyRequest;
use App\Services\Core\CompanyMembershipService;
use Illuminate\Http\RedirectResponse;

class CompanySwitchController extends Controller
{
    public function __invoke(SwitchCompanyRequest $request, CompanyMembershipService $memberships): RedirectResponse
    {
        $company = $request->company();
        abort_if($company === null, 404);

        $memberships->switchTo($request->user(), $company);

        // The page the user was on belongs to the company they just left.
        return redirect()->route('admin.dashboard')->with('success', 'Sekarang bekerja di '.$company->name.'.');
    }
}
