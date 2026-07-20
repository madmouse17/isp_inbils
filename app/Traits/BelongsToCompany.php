<?php

namespace App\Traits;

use App\Models\Core\Company;
use App\Services\Core\CompanyService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

trait BelongsToCompany
{
    public static function bootBelongsToCompany(): void
    {
        static::addGlobalScope('company', function (Builder $builder) {
            $companyId = CompanyService::currentId();

            if ($companyId !== null) {
                $builder->where($builder->getModel()->getTable().'.company_id', $companyId);
            }
        });

        static::creating(function (Model $model) {
            $companyId = CompanyService::currentId();

            if ($companyId !== null && $model->getAttribute('company_id') === null) {
                $model->setAttribute('company_id', $companyId);
            }
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

        return $query->forCompany($companyId);
    }
}
