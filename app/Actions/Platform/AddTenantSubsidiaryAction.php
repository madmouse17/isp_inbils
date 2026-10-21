<?php

namespace App\Actions\Platform;

use App\Models\Core\Company;
use App\Models\PlatformAdmin;
use App\Models\User;
use App\Services\Core\CompanyHierarchyService;
use App\Services\Core\CompanyMembershipService;
use App\Services\Core\CompanyRoleService;
use App\Services\Platform\PlatformAuditService;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Adds a subsidiary to a tenant through CompanyHierarchyService (same defaults as every new company) and enrols the
 * chosen members, all or nothing. A suspended tenant gets none: the new company would start active inside a
 * suspended group. Members keep their home company; StoreSubsidiaryRequest proved they belong to this tenant.
 *
 * Roles are per company, so each member also receives the subsidiary's copy of every template role he holds at home, as
 * far as he holds what that copy grants (CompanyRoleService::mirrorTemplateRoles): a company that trimmed its own copy
 * does not see it widened by the new one. A member who got no role, and the roles left out for a person to give, are
 * reported to the operator so they can be named in Pengguna; the audit row lists what each member was given.
 */
class AddTenantSubsidiaryAction
{
    public function __construct(
        private readonly CompanyHierarchyService $hierarchy,
        private readonly CompanyMembershipService $memberships,
        private readonly CompanyRoleService $roles,
        private readonly PlatformAuditService $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $validated  what StoreSubsidiaryRequest validated
     * @return array{company: Company, members_without_roles: list<string>, roles_by_hand: list<array{member: string, roles: list<string>}>} the names of the members who got no role in the subsidiary, and the roles left out per member
     */
    public function execute(PlatformAdmin $actor, Company $root, array $validated): array
    {
        return DB::transaction(function () use ($actor, $root, $validated): array {
            $head = Company::query()->lockForUpdate()->findOrFail($root->id);

            if (! $head->is_active) {
                throw ValidationException::withMessages(['lifecycle' => 'Perusahaan sedang ditangguhkan: aktifkan kembali sebelum menambah anak perusahaan.']);
            }

            $subsidiary = $this->hierarchy->createSubsidiary($head, Arr::only($validated, ['name', 'code', 'currency', 'timezone']));
            $memberIds = array_map('intval', $validated['member_ids'] ?? []);
            $withoutRoles = [];
            $withoutRoleNames = [];
            $memberRoles = [];
            $byHandRoles = [];

            foreach (User::query()->with('company')->whereIn('id', $memberIds)->get() as $member) {
                $this->memberships->grant($member, $subsidiary);

                ['granted' => $granted, 'by_hand' => $byHand] = $this->roles->mirrorTemplateRoles($member, $member->company, $subsidiary);
                $memberRoles[] = ['user_id' => $member->id, 'granted' => $granted, 'by_hand' => $byHand];

                if ($granted === []) {
                    $withoutRoles[] = $member->id;
                    $withoutRoleNames[] = $member->name;
                }

                if ($byHand !== []) {
                    $byHandRoles[] = ['member' => $member->name, 'roles' => $byHand];
                }
            }

            $this->audit->platform('tenant.subsidiary_created', [
                'parent_company_id' => $head->id,
                'code' => $subsidiary->code,
                'member_ids' => $memberIds,
                'members_without_roles' => $withoutRoles,
                'member_roles' => $memberRoles,
            ], $subsidiary, $actor);

            return ['company' => $subsidiary, 'members_without_roles' => $withoutRoleNames, 'roles_by_hand' => $byHandRoles];
        });
    }
}
