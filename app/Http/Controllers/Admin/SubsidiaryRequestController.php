<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreSubsidiaryRequestRequest;
use App\Models\User;
use App\Services\Core\CompanyService;
use App\Services\Platform\SubsidiaryRequestService;
use Illuminate\Http\RedirectResponse;

/** "Minta anak perusahaan": the tenant admin's request to the platform operator, who creates subsidiaries. */
class SubsidiaryRequestController extends Controller
{
    public function store(StoreSubsidiaryRequestRequest $request, SubsidiaryRequestService $requests): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $requests->submit($user, CompanyService::current() ?? abort(403), $request->validated());

        return back()->with('success', 'Permintaan anak perusahaan dikirim ke operator.');
    }
}
