<?php

namespace Modules\Reporting\Http\Requests;

use App\Models\Core\Company;
use App\Services\Core\CompanyHierarchyService;
use App\Services\Core\CompanyService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Collection;

/**
 * The consolidated Business report of the Head company the user is acting in. Allowed with
 * `report.consolidated.view` while the effective company is a Head: the permission is global, so it
 * alone never opens another company's figures. The companies in the report are derived from the
 * hierarchy, never from the request.
 */
class ConsolidatedReportRequest extends FormRequest
{
    private ?Company $head = null;

    public function authorize(): bool
    {
        return ($this->user()?->can('report.consolidated.view') ?? false) && $this->head() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ];
    }

    /** The effective company when it is a Head, else null. */
    public function head(): ?Company
    {
        if ($this->head === null) {
            $company = CompanyService::current();
            $this->head = $company?->isHead() ? $company : null;
        }

        return $this->head;
    }

    /**
     * The Head and its active subsidiaries.
     *
     * @return Collection<int, Company>
     */
    public function companies(): Collection
    {
        return app(CompanyHierarchyService::class)->consolidationSet($this->head() ?? abort(403));
    }

    public function dateFrom(): ?string
    {
        return $this->input('date_from') ?: null;
    }

    public function dateTo(): ?string
    {
        return $this->input('date_to') ?: null;
    }
}
