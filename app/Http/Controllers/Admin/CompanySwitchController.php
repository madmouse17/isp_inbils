<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SwitchCompanyRequest;
use App\Services\Core\CompanyMembershipService;
use App\Support\RecordNotFound;
use Illuminate\Http\RedirectResponse;

class CompanySwitchController extends Controller
{
    public function __invoke(SwitchCompanyRequest $request, CompanyMembershipService $memberships): RedirectResponse
    {
        $company = $request->company();
        RecordNotFound::abortIf($company === null);

        $memberships->switchTo($request->user(), $company);

        // The page the user was on belongs to the company they just left.
        return redirect()->route('admin.dashboard')->with('success', 'Sekarang bekerja di '.$company->name.'.');
    }
}
