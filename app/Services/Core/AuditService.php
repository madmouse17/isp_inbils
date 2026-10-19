<?php

namespace App\Services\Core;

use App\Models\Core\Company;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Contracts\Activity as ActivityContract;

class AuditService
{
    /**
     * Log an action with automatic tenant/company capture. The action is the description and the `event` of the row.
     * The causer is the authenticated user of the default guard, unless $causer names one: a platform login flow logs
     * before its session exists.
     *
     * @param  array<string, mixed>  $properties
     */
    public static function log(string $module, string $action, array $properties = [], ?Model $subject = null, ?int $companyId = null, ?Model $causer = null): ?ActivityContract
    {
        $resolvedCompanyId = self::resolveCompanyId($subject, $properties, $companyId);

        // The company lives in its column (tapped below); the Activity::saving hook drops any `company_id` property.
        $activity = activity($module)->event($action)->withProperties($properties);

        if ($subject) {
            $activity->performedOn($subject);
        }

        if ($causer !== null) {
            $activity->causedBy($causer);
        }

        if ($resolvedCompanyId !== null) {
            $activity->tap(function ($act) use ($resolvedCompanyId) {
                $act->company_id = $resolvedCompanyId;
            });
        }

        return $activity->log($action);
    }

    /**
     * Resolve company_id from explicit value, subject, company context, auth user, or properties.
     *
     * @param  array<string, mixed>  $properties
     */
    public static function resolveCompanyId(?Model $subject = null, array $properties = [], ?int $explicitCompanyId = null): ?int
    {
        $provenCompanyId = null;

        if ($subject !== null) {
            if ($subject instanceof Company) {
                $provenCompanyId = (int) $subject->id;
            } elseif ($subject->getAttribute('company_id') !== null) {
                $provenCompanyId = (int) $subject->getAttribute('company_id');
            }
        }

        if ($provenCompanyId === null) {
            $currentId = CompanyService::currentId();
            if ($currentId !== null) {
                $provenCompanyId = (int) $currentId;
            }
        }

        // Validate explicit argument against server-authoritative evidence:
        // Do not accept explicit arg if conflicting or contextless (unproven).
        if ($explicitCompanyId !== null) {
            if ($provenCompanyId === null) {
                // Without proof from subject/context/auth, unproven explicit arg cannot establish tenant
                return null;
            }

            // Conflicting explicit arg is rejected in favor of proven tenant
            return $provenCompanyId;
        }

        return $provenCompanyId;
    }
}
