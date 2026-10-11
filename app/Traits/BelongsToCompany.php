<?php

namespace App\Traits;

use App\Models\Core\Company;
use App\Services\Core\CompanyService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Contracts\Activity;

trait BelongsToCompany
{
    public static function bootBelongsToCompany(): void
    {
        static::addGlobalScope('company', function (Builder $builder) {
            $column = $builder->getModel()->getTable().'.company_id';
            $companyId = CompanyService::currentId();

            // Fail closed: without a company context nothing matches. Cross-company code must say so
            // with withoutCompany(), forCompany() or CompanyService::runAs().
            if ($companyId === null) {
                $builder->whereIn($column, []);

                return;
            }

            $builder->where($column, $companyId);
        });

        static::creating(function (Model $model) {
            if ($model->getAttribute('company_id') !== null) {
                return;
            }

            $companyId = CompanyService::currentId();

            abort_if($companyId === null, 403, 'Company context is required.');

            $model->setAttribute('company_id', $companyId);
        });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function scopeWithoutCompany(Builder $query): Builder
    {
        return $query->withoutGlobalScope('company');
    }

    public function scopeForCompany(Builder $query, ?int $companyId): Builder
    {
        abort_if($companyId === null, 403, 'Company context is required.');

        return $query->withoutGlobalScope('company')->where($query->getModel()->getTable().'.company_id', $companyId);
    }

    public function scopeForCurrentCompany(Builder $query): Builder
    {
        $companyId = CompanyService::currentId();

        abort_if($companyId === null, 403, 'Company context is required.');

        return $this->scopeForCompany($query, $companyId);
    }

    public function tapActivity(Activity $activity, string $eventName): void
    {
        $companyId = $this->getAttribute('company_id') ?? CompanyService::currentId();
        if ($companyId !== null) {
            /** @var \Spatie\Activitylog\Models\Activity $activity */
            $activity->setAttribute('company_id', (int) $companyId);
        }
    }
}
