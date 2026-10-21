<?php

namespace App\Rules;

use App\Services\Core\CompanyService;
use App\Support\Notifications\StaffRecipients;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * The submitted user id names an active member of the company who holds one of the permissions there, whatever the
 * company calls its roles. Reusable wherever an assignment FK needs a capability check (ticket handler, evaluated
 * employee). The value is the integer id: requests resolve public ids to it first (ResolvesPublicIds).
 */
class HoldsPermission implements ValidationRule
{
    /**
     * @param  list<string>  $permissions  any one of them is enough
     * @param  int|null  $companyId  null: the company the request acts in
     */
    public function __construct(private readonly array $permissions, private readonly ?int $companyId = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $companyId = $this->companyId ?? CompanyService::currentId();

        if ($companyId === null) {
            $fail('Konteks perusahaan diperlukan.');

            return;
        }

        $holds = is_int($value)
            && StaffRecipients::members($companyId)->withPermissionIn($this->permissions, $companyId)->whereKey($value)->exists();

        if (! $holds) {
            $fail(':attribute yang dipilih harus berupa pengguna aktif dengan hak akses yang sesuai.');
        }
    }
}
