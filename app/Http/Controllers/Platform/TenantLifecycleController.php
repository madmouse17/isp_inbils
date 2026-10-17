<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\Tenants\ReactivateTenantRequest;
use App\Http\Requests\Platform\Tenants\RequestTenantDeletionRequest;
use App\Http\Requests\Platform\Tenants\SuspendTenantRequest;
use App\Models\Core\Company;
use App\Models\PlatformAdmin;
use App\Services\Platform\TenantLifecycleService;
use Illuminate\Http\RedirectResponse;

/** Suspend, reactivate and request deletion of a tenant. The rules, the locking and the audit are the service's. */
class TenantLifecycleController extends Controller
{
    public function suspend(SuspendTenantRequest $request, Company $company, TenantLifecycleService $tenants): RedirectResponse
    {
        $tenants->suspend($this->admin($request), $company, $request->validated('reason'));

        return $this->shown($company, 'Perusahaan ditangguhkan.');
    }

    public function reactivate(ReactivateTenantRequest $request, Company $company, TenantLifecycleService $tenants): RedirectResponse
    {
        $tenants->reactivate($this->admin($request), $company, $request->validated('reason'));

        return $this->shown($company, 'Perusahaan diaktifkan kembali.');
    }

    public function requestDeletion(RequestTenantDeletionRequest $request, Company $company, TenantLifecycleService $tenants): RedirectResponse
    {
        $tenants->requestDeletion($this->admin($request), $company);

        return $this->shown($company, 'Pengajuan hapus dicatat. Tidak ada data yang dihapus.');
    }

    private function admin(RequestTenantDeletionRequest|ReactivateTenantRequest|SuspendTenantRequest $request): PlatformAdmin
    {
        /** @var PlatformAdmin */
        return $request->user('platform');
    }

    private function shown(Company $company, string $message): RedirectResponse
    {
        return redirect()->route('platform.companies.show', $company)->with('success', $message);
    }
}
