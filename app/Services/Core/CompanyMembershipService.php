<?php

namespace App\Services\Core;

use App\Models\Core\Company;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Who may act in which company, and the switch between them. The default membership is the home
 * company (users.company_id); any other membership is an explicit grant. Roles belong to the company they are held
 * in (ADR-004): a grant gives none by itself (CompanyRoleService::mirrorTemplateRoles gives the template-matched
 * ones), and a revoke takes away those of that company only.
 */
class CompanyMembershipService
{
    /**
     * Grants membership; idempotent. The default membership must be the user's home company, and
     * a user without a home company gets it as their home.
     */
    public function grant(User $user, Company $company, bool $default = false): void
    {
        $changed = DB::transaction(function () use ($user, $company, $default): bool {
            if ($default) {
                abort_unless(
                    $user->company_id === null || (int) $user->company_id === (int) $company->id,
                    422,
                    'The default membership must be the home company of the user.'
                );

                if ($user->company_id === null) {
                    $user->forceFill(['company_id' => $company->id])->save();
                }

                DB::table('company_user')
                    ->where('user_id', $user->id)
                    ->where('company_id', '<>', $company->id)
                    ->update(['is_default' => false]);
            }

            $membership = DB::table('company_user')->where('company_id', $company->id)->where('user_id', $user->id);

            if (! $membership->exists()) {
                try {
                    DB::table('company_user')->insert([
                        'company_id' => $company->id,
                        'user_id' => $user->id,
                        'is_default' => $default,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    return true;
                } catch (UniqueConstraintViolationException $e) {
                    // A parallel grant won the race: the membership exists, so carry on as for an existing one.
                    throw_unless($membership->exists(), $e);
                }
            }

            return $default && $membership->where('is_default', false)->update(['is_default' => true, 'updated_at' => now()]) > 0;
        });

        if ($changed) {
            AuditService::log('company', 'membership_granted', ['user_id' => $user->id, 'is_default' => $default], $company);
        }

        // The instance may carry the memberships it was loaded with (User::companyIds()).
        $user->unsetRelation('companies');
        CompanyService::forgetResolution();
    }

    /**
     * Revokes membership and the roles the user held in that company; his roles in other companies stay. The home
     * company is not a grant and cannot be revoked. An administrator is not revoked while he is the company's last one
     * (G5): the company row is locked, so two revocations cannot both pass.
     */
    public function revoke(User $user, Company $company): void
    {
        abort_if(
            $user->company_id !== null && (int) $user->company_id === (int) $company->id,
            422,
            'The home company membership cannot be revoked.'
        );

        $roles = app(CompanyRoleService::class);

        $deleted = $roles->transaction((int) $company->id, function () use ($user, $company, $roles): int {
            $administrator = $roles->isAdministrator($user, (int) $company->id);
            $deleted = DB::table('company_user')->where('company_id', $company->id)->where('user_id', $user->id)->delete();

            if ($deleted > 0) {
                // The transaction acts as this company, so only its roles are detached.
                $user->syncRoles([]);

                if ($administrator) {
                    $roles->assertAdministratorRemains((int) $company->id);
                }
            }

            return $deleted;
        });

        if ($deleted > 0) {
            AuditService::log('company', 'membership_revoked', ['user_id' => $user->id], $company);
        }

        $user->unsetRelation('companies');
        CompanyService::forgetResolution();
    }

    /**
     * Makes $company the user's active company for the rest of the session and audits the change
     * under the company that was entered. Switching to the home company clears the switch.
     */
    public function switchTo(User $user, Company $company): void
    {
        abort_unless(CompanyService::canActIn($user, (int) $company->id), 403);

        $from = CompanyService::idFor($user);

        if ($user->company_id !== null && (int) $user->company_id === (int) $company->id) {
            session()->forget(CompanyService::SESSION_KEY);
        } else {
            session()->put(CompanyService::SESSION_KEY, (int) $company->id);
        }

        if ($from !== (int) $company->id) {
            AuditService::log('company', 'company_switched', [
                'from_company_id' => $from,
                'to_company_id' => (int) $company->id,
            ], $company);
        }
    }

    /**
     * The companies the user may act in, home company first, as the switcher lists them.
     *
     * @return Collection<int, array{id: string, name: string, code: string, is_current: bool, is_default: bool}>
     */
    public function companiesOf(User $user): Collection
    {
        $home = $user->company_id !== null ? (int) $user->company_id : null;
        $current = CompanyService::idFor($user);

        return Company::query()
            ->where(fn ($query) => $query
                ->whereKey($home)
                ->orWhere(fn ($granted) => $granted
                    ->where('is_active', true)
                    ->whereHas('members', fn ($members) => $members->whereKey($user->getKey()))))
            ->get(['id', 'public_id', 'name', 'code'])
            ->sortBy(fn (Company $company): string => ($company->id === $home ? '0' : '1').mb_strtolower($company->name))
            ->map(fn (Company $company): array => [
                'id' => $company->public_id,
                'name' => $company->name,
                'code' => $company->code,
                'is_current' => $company->id === $current,
                'is_default' => $company->id === $home,
            ])
            ->values();
    }

    /**
     * Whether the actor belongs to every company the target belongs to, active or not: the actor then
     * answers for everything a change to the target's login (email, password, roles, status) reaches.
     *
     * A read for the authorization of what is shown: it uses the memberships the two users carry (User::companyIds()),
     * which a page of users loads for all its rows at once. Whoever writes on the strength of the memberships uses
     * companyIdsOf(), which asks the table.
     */
    public function administers(User $actor, User $target): bool
    {
        return $target->companyIds()->diff($actor->companyIds())->isEmpty();
    }

    /**
     * The home company plus every granted membership, as the table has them now.
     *
     * @return Collection<int, int>
     */
    public function companyIdsOf(User $user): Collection
    {
        return DB::table('company_user')
            ->where('user_id', $user->getKey())
            ->pluck('company_id')
            ->push($user->company_id)
            ->filter()
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values();
    }
}
