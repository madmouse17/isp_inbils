<?php

namespace App\Services\Core;

use App\Models\Core\Company;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Contracts\Activity as ActivityContract;

class AuditService
{
    /**
     * Log an action with automatic tenant/company capture.
     *
     * @param  array<string, mixed>  $properties
     */
    public static function log(string $module, string $action, array $properties = [], ?Model $subject = null, ?int $companyId = null): ?ActivityContract
    {
        $resolvedCompanyId = self::resolveCompanyId($subject, $properties, $companyId);

        if ($resolvedCompanyId !== null) {
            $properties['company_id'] = $resolvedCompanyId;
        } else {
            unset($properties['company_id']);
        }

        $activity = activity($module)->withProperties($properties);

        if ($subject) {
            $activity->performedOn($subject);
        }

        if ($resolvedCompanyId !== null) {
            $activity->tap(function ($act) use ($resolvedCompanyId) {
                $act->company_id = $resolvedCompanyId;
            });
        }

        return $activity->log($action);
    }

    /**
     * Log model lifecycle activity with automatic company capture.
     */
    public static function logModelActivity(Model $model, string $event, ?int $companyId = null): ?ActivityContract
    {
        $attributes = $model->getAttributes();
        $resolvedCompanyId = self::resolveCompanyId($model, $attributes, $companyId);

        if ($resolvedCompanyId !== null) {
            $attributes['company_id'] = $resolvedCompanyId;
        } else {
            unset($attributes['company_id']);
        }

        $activity = activity(strtolower(class_basename($model)))
            ->performedOn($model)
            ->withProperties($attributes);

        if ($resolvedCompanyId !== null) {
            $activity->tap(function ($act) use ($resolvedCompanyId) {
                $act->company_id = $resolvedCompanyId;
            });
        }

        return $activity->log($event);
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
