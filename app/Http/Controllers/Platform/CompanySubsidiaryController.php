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
        $result = $add->execute($admin, $company, $request->validated());

        // Members who hold only custom roles at home get no role in the new company, and a template role wider than what a
        // member holds at home is not copied: the operator is told who and what, so the roles can be named in Pengguna.
        $message = 'Anak perusahaan dibuat.';

        if ($result['members_without_roles'] !== []) {
            $message .= ' Anggota ini belum punya peran di anak perusahaan baru dan perlu diberi peran di Pengguna: '.implode(', ', $result['members_without_roles']).'.';
        }

        if ($result['roles_by_hand'] !== []) {
            $left = array_map(fn (array $row): string => $row['member'].' ('.implode(', ', $row['roles']).')', $result['roles_by_hand']);
            $message .= ' Peran ini lebih luas daripada yang dipegang anggotanya di perusahaan asal, jadi tidak disalin dan perlu diberikan sendiri di Pengguna: '.implode('; ', $left).'.';
        }

        return redirect()->route('platform.companies.show', $company)->with('success', $message);
    }
}
