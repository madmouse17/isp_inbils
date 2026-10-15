<?php

namespace App\Http\Requests\Admin;

use App\Models\Core\EmployeeEvaluation;
use App\Rules\HasAnyActiveRole;
use App\Rules\PolymorphicBelongsToCompany;
use App\Services\Core\CompanyService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Modules\SPK\Models\WorkOrder;
use Modules\Ticketing\Models\Ticket;

class StoreEvaluationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('evaluation.create') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $companyId = CompanyService::currentId();

        return [
            'employee_id' => [
                'bail',
                'required',
                Rule::exists('users', 'id')->where(fn (Builder $query) => $query
                    ->where('is_active', true)
                    ->whereIn('id', DB::table('company_user')->where('company_id', $companyId)->select('user_id'))),
                new HasAnyActiveRole(EmployeeEvaluation::EVALUABLE_ROLES),
            ],
            'reference_type' => ['required', Rule::in(array_keys(self::referenceMap()))],
            'reference_id' => ['required', 'integer', new PolymorphicBelongsToCompany(self::referenceMap(), 'reference_type')],
            'score' => ['required', 'numeric', 'between:1.0,5.0'],
            'customer_rating' => ['nullable', 'numeric', 'between:1.0,5.0'],
            'comment' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, class-string<Model>>
     */
    private static function referenceMap(): array
    {
        return [
            'WorkOrder' => WorkOrder::class,
            'Ticket' => Ticket::class,
        ];
    }
}
