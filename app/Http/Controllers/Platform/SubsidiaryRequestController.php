<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\Tenants\RejectSubsidiaryRequestRequest;
use App\Models\Core\Company;
use App\Models\PlatformAdmin;
use App\Services\Platform\SubsidiaryRequestService;
use Illuminate\Http\RedirectResponse;

/**
 * A tenant's request for a subsidiary (K34), seen from the operator's side. Creating the subsidiary closes the request
 * through CompanySubsidiaryController; turning it down is here. `{company}` is a tenant, `{subsidiaryRequest}` the
 * public id of the request row: the service answers 404 for one that belongs to no such tenant.
 */
class SubsidiaryRequestController extends Controller
{
    public function reject(RejectSubsidiaryRequestRequest $request, Company $company, string $subsidiaryRequest, SubsidiaryRequestService $requests): RedirectResponse
    {
        /** @var PlatformAdmin $admin */
        $admin = $request->user('platform');
        $mailed = $requests->reject($admin, $company, $subsidiaryRequest, $request->validated('reason'));

        return redirect()->route('platform.companies.show', $company)->with('success', $mailed
            ? 'Permintaan ditolak. Alasannya dikirim ke pemohon lewat email.'
            : 'Permintaan ditolak. Pemohon tidak bisa dikirimi email, jadi sampaikan alasannya langsung.');
    }
}
