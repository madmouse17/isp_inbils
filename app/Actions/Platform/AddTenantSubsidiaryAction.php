<?php

namespace App\Actions\Platform;

use App\Models\Core\Company;
use App\Models\PlatformAdmin;
use App\Models\User;
use App\Services\Core\CompanyHierarchyService;
use App\Services\Core\CompanyMembershipService;
use App\Services\Platform\PlatformAuditService;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Adds a subsidiary to a tenant through CompanyHierarchyService (same defaults as every new company) and enrols the
 * chosen members, all or nothing. A suspended tenant gets none: the new company would start active inside a
 * suspended group. Members keep their home company; StoreSubsidiaryRequest proved they belong to this tenant.
 */
class AddTenantSubsidiaryAction
{
    public function __construct(
        private readonly CompanyHierarchyService $hierarchy,
        private readonly CompanyMembershipService $memberships,
        private readonly PlatformAuditService $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $validated  what StoreSubsidiaryRequest validated
     */
    public function execute(PlatformAdmin $actor, Company $root, array $validated): Company
    {
        return DB::transaction(function () use ($actor, $root, $validated): Company {
            $head = Company::query()->lockForUpdate()->findOrFail($root->id);

            if (! $head->is_active) {
                throw ValidationException::withMessages(['lifecycle' => 'Perusahaan sedang ditangguhkan: aktifkan kembali sebelum menambah anak perusahaan.']);
            }

            $subsidiary = $this->hierarchy->createSubsidiary($head, Arr::only($validated, ['name', 'code', 'currency', 'timezone']));
            $memberIds = array_map('intval', $validated['member_ids'] ?? []);

            foreach (User::query()->whereIn('id', $memberIds)->get() as $member) {
                $this->memberships->grant($member, $subsidiary);
            }

            $this->audit->platform('tenant.subsidiary_created', [
                'parent_company_id' => $head->id,
                'code' => $subsidiary->code,
                'member_ids' => $memberIds,
            ], $subsidiary, $actor);

            return $subsidiary;
        });
    }
}
