<?php

namespace Modules\Reporting\Services;

use App\Models\Core\EmployeeProfile;
use App\Models\User;
use App\Services\Core\CompanyService;
use Modules\Reporting\Queries\TechnicianPerformanceQuery;

/**
 * Who may open the Technician Performance report, and for which technician. The report screen, its
 * export and its drill-down lists all resolve their target here, so none can see more than the
 * screen does: admin (`report.manage`) any technician of the company, a manager the technicians of
 * their own organization unit subtree, a technician only themself.
 */
final class TechnicianReportAccess
{
    /** Company of an actor allowed on the report; aborts 403 for everybody else. */
    public static function companyId(?User $actor): int
    {
        $companyId = self::allowedCompanyId($actor);
        abort_unless($companyId !== null, 403);

        return $companyId;
    }

    /** The non-aborting twin of companyId(): may this actor open the report at all (sidebar flag). */
    public static function allows(?User $actor): bool
    {
        return self::allowedCompanyId($actor) !== null;
    }

    private static function allowedCompanyId(?User $actor): ?int
    {
        $companyId = $actor === null ? null : CompanyService::idFor($actor);
        if ($companyId === null) {
            return null;
        }

        if ($actor->can('report.manage')) {
            return $companyId;
        }

        if ($actor->hasRole('manager') && $actor->can('report.technician')) {
            $profile = EmployeeProfile::query()
                ->where('employee_profiles.company_id', $companyId)
                ->where('employee_profiles.user_id', $actor->id)
                ->where('employee_profiles.status', 'active')
                ->whereHas('organization', fn ($query) => $query
                    ->where('organization_units.company_id', $companyId)
                    ->where('organization_units.is_active', true)
                    ->whereNotNull('organization_units.path'))
                ->with('organization')
                ->first();

            return self::isValidOrganizationPath($profile?->organization?->path) ? $companyId : null;
        }

        return $actor->hasRole('technician') && $actor->can('report.technician') ? $companyId : null;
    }

    /**
     * The technician a request targets, named by public id: the requested one when the actor may select it (403
     * otherwise, an unknown or foreign id included), the actor themself for a technician who asked for none, else
     * null.
     */
    public static function target(User $actor, ?string $requestedId): ?User
    {
        if ($requestedId === null) {
            $technician = $actor->hasRole('technician') ? $actor : null;
        } else {
            $technician = User::query()->wherePublicId($requestedId)->first();
            abort_if($technician === null, 403);
        }

        if ($technician === null) {
            return null;
        }

        abort_unless(TechnicianPerformanceQuery::canSelect($actor, $technician->id), 403);

        return $technician;
    }

    /**
     * Structural validation only. Unit codes are free-form strings, so `%` and `_` are
     * legitimate characters; the descendant LIKE match binds them as literals via
     * TechnicianPerformanceQuery, which is what keeps the scope narrow.
     */
    private static function isValidOrganizationPath(?string $path): bool
    {
        if ($path === null || $path === '') {
            return false;
        }

        $segments = explode(' > ', $path);

        return collect($segments)->every(fn (string $segment): bool => $segment !== trim($segment)
            ? false
            : $segment !== ''
                && ! str_contains($segment, '>')
                && ! str_contains($segment, '\\'));
    }
}
