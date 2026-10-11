<?php

namespace App\Services\Core;

use App\Models\Core\Company;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Who may act in which company, and the switch between them. The default membership is the home
 * company (users.company_id); any other membership is an explicit grant. Roles stay global.
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
                DB::table('company_user')->insert([
                    'company_id' => $company->id,
                    'user_id' => $user->id,
                    'is_default' => $default,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                return true;
            }

            return $default && $membership->where('is_default', false)->update(['is_default' => true, 'updated_at' => now()]) > 0;
        });

        if ($changed) {
            AuditService::log('company', 'membership_granted', ['user_id' => $user->id, 'is_default' => $default], $company);
        }

        CompanyService::forgetResolution();
    }

    /** Revokes membership. The home company is not a grant and cannot be revoked. */
    public function revoke(User $user, Company $company): void
    {
        abort_if(
            $user->company_id !== null && (int) $user->company_id === (int) $company->id,
            422,
            'The home company membership cannot be revoked.'
        );

        $deleted = DB::table('company_user')->where('company_id', $company->id)->where('user_id', $user->id)->delete();

        if ($deleted > 0) {
            AuditService::log('company', 'membership_revoked', ['user_id' => $user->id], $company);
        }

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
     * @return Collection<int, array{id: int, name: string, code: string, is_current: bool, is_default: bool}>
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
            ->get(['id', 'name', 'code'])
            ->sortBy(fn (Company $company): string => ($company->id === $home ? '0' : '1').mb_strtolower($company->name))
            ->map(fn (Company $company): array => [
                'id' => $company->id,
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
     */
    public function administers(User $actor, User $target): bool
    {
        return $this->companyIdsOf($target)->diff($this->companyIdsOf($actor))->isEmpty();
    }

    /**
     * The home company plus every granted membership.
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
