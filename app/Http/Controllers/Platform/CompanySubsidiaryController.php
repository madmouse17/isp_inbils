<?php

namespace App\Http\Controllers\Platform;

use App\Actions\Platform\AddTenantSubsidiaryAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\Tenants\StoreSubsidiaryRequest;
use App\Models\Core\Company;
use App\Models\PlatformAdmin;
use Illuminate\Http\RedirectResponse;

class CompanySubsidiaryController extends Controller
{
    public function store(StoreSubsidiaryRequest $request, Company $company, AddTenantSubsidiaryAction $add): RedirectResponse
    {
        /** @var PlatformAdmin $admin */
        $admin = $request->user('platform');
        $add->execute($admin, $company, $request->validated());

        return redirect()->route('platform.companies.show', $company)->with('success', 'Anak perusahaan dibuat.');
    }
}
