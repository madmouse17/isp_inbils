<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Ensure child row's parent FK matches another submitted attribute (same-company parent-child consistency).
 *
 * Example: address_id.customer_id must equal submitted customer_id.
 *
 * @param  class-string<Model>|string  $tableOrModel
 */
class MatchesParentAttribute implements DataAwareRule, ValidationRule
{
    /** @var array<string, mixed> */
    private array $data = [];

    public function __construct(
        private readonly string $tableOrModel,
        private readonly string $parentColumn,
        private readonly string $parentAttribute,
        private readonly string $column = 'id',
    ) {}

    public function setData(array $data): static
    {
        $this->data = $data;

        return $this;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        $expectedParent = data_get($this->data, $this->parentAttribute);
        if ($expectedParent === null || $expectedParent === '') {
            return;
        }

        $table = $this->resolveTable();
        $row = DB::table($table)
            ->where($this->column, $value)
            ->when($this->hasSoftDeletes($table), fn ($q) => $q->whereNull('deleted_at'))
            ->first([$this->parentColumn]);

        if ($row === null) {
            // Existence handled by separate company rule.
            return;
        }

        if ((string) $row->{$this->parentColumn} !== (string) $expectedParent) {
            $fail("The selected :attribute does not belong to the selected {$this->parentAttribute}.");
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
