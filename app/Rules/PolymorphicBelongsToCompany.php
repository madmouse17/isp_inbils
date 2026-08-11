<?php

namespace App\Rules;

use App\Services\Core\CompanyService;
use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Model;

/**
 * Allowlisted morph type + same-company existence for morph id.
 *
 * @param  array<string, class-string<Model>>  $typeMap  morph alias => model class
 */
class PolymorphicBelongsToCompany implements DataAwareRule, ValidationRule
{
    /** @var array<string, mixed> */
    private array $data = [];

    /**
     * @param  array<string, class-string<Model>>  $typeMap
     */
    public function __construct(
        private readonly array $typeMap,
        private readonly string $typeAttribute,
        private readonly string $companyColumn = 'company_id',
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

        $type = data_get($this->data, $this->typeAttribute);
        if (! is_string($type) || $type === '' || ! array_key_exists($type, $this->typeMap)) {
            $fail('The selected reference type is not allowed.');

            return;
        }

        $companyId = CompanyService::currentId();
        if ($companyId === null) {
            $fail('Company context is required.');

            return;
        }

        $modelClass = $this->typeMap[$type];
        $exists = $modelClass::query()
            ->whereKey($value)
            ->where($this->companyColumn, $companyId)
            ->exists();

        if (! $exists) {
            $fail('The selected :attribute is invalid for this company.');
        }
    }
}
