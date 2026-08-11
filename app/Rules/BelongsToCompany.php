<?php

namespace App\Rules;

use App\Services\Core\CompanyService;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Fail-closed company-owned FK existence check.
 *
 * @param  class-string<Model>|string  $tableOrModel  Eloquent model class or table name
 */
class BelongsToCompany implements ValidationRule
{
    public function __construct(
        private readonly string $tableOrModel,
        private readonly string $column = 'id',
        private readonly string $companyColumn = 'company_id',
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        $companyId = CompanyService::currentId();
        if ($companyId === null) {
            $fail('Company context is required.');

            return;
        }

        $table = $this->resolveTable();
        $exists = DB::table($table)
            ->where($this->column, $value)
            ->where($this->companyColumn, $companyId)
            ->when($this->hasSoftDeletes($table), fn ($q) => $q->whereNull('deleted_at'))
            ->exists();

        if (! $exists) {
            $fail('The selected :attribute is invalid for this company.');
        }
    }

    private function resolveTable(): string
    {
        if (is_subclass_of($this->tableOrModel, Model::class)) {
            return (new $this->tableOrModel())->getTable();
        }

        return $this->tableOrModel;
    }

    private function hasSoftDeletes(string $table): bool
    {
        return in_array($table, [
            'customers',
            'customer_addresses',
            'locations',
            'service_packages',
            'products',
            'tickets',
            'ticket_categories',
            'work_orders',
            'service_subscriptions',
            'invoices',
            'network_assets',
        ], true);
    }
}
