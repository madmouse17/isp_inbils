<?php

namespace App\Rules;

use App\Queries\Admin\CustomerAcquirerQuery;
use App\Services\Core\CompanyService;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * The submitted user id must be an eligible acquirer of the current company (CustomerAcquirerQuery).
 * Unknown, foreign-company and ineligible ids fail with the same message, so a response never
 * reveals whether a user of another tenant exists.
 */
class IsEligibleCustomerAcquirer implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $companyId = CompanyService::currentId();

        if ($companyId === null || ! CustomerAcquirerQuery::isId($value) || ! CustomerAcquirerQuery::isEligible($companyId, (int) $value)) {
            $fail('Pilih karyawan aktif yang berhak membuat pelanggan.');
        }
    }
}
