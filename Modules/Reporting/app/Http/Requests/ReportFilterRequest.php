<?php

namespace Modules\Reporting\Http\Requests;

use App\Services\Core\CompanyService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Modules\Reporting\Queries\SalesKpiQuery;
use Modules\Reporting\Registry\ReportType;

class ReportFilterRequest extends FormRequest
{
    /**
     * A report is authorized by the capability the report catalog assigns to the matched route,
     * so route middleware and this request can never drift apart. Unknown routes fail closed.
     */
    public function authorize(): bool
    {
        $report = ReportType::fromRouteName($this->route()?->getName());

        return $report !== null && ($this->user()?->can($report->permission()) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $sortColumns = match (true) {
            $this->routeIs('admin.reports.stock-card') => ['nullable', 'string', 'in:created_at,quantity,balance_after'],
            $this->routeIs('admin.reports.sales') => ['nullable', 'string', 'in:'.implode(',', SalesKpiQuery::SORTABLE)],
            default => ['nullable', 'string', 'in:id,created_at,log_name,event,description'],
        };

        return [
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'technician_id' => ['nullable', 'integer'],
            'location_id' => ['nullable', 'integer'],
            'asset_type' => ['nullable', 'string'],
            'category_id' => ['nullable', 'integer'],
            'product_id' => ['nullable', 'integer'],
            'acquirer_id' => ['nullable', 'integer'],
            'user_id' => ['nullable', 'integer'],
            'log_name' => ['nullable', 'string'],
            'event' => ['nullable', 'string', 'max:50'],
            'search' => ['nullable', 'string', 'max:255'],
            'service_package_category_id' => ['nullable', 'integer', Rule::exists('service_package_categories', 'id')->where('company_id', CompanyService::currentId())],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'sort' => $sortColumns,
            'direction' => ['nullable', 'string', 'in:asc,desc,ASC,DESC'],
            'sort_by' => $sortColumns,
            'sort_dir' => ['nullable', 'string', 'in:asc,desc,ASC,DESC'],
        ];
    }

    public function dateFrom(): ?string
    {
        return $this->input('date_from');
    }

    public function dateTo(): ?string
    {
        $dateTo = $this->input('date_to');

        return $dateTo ? Carbon::parse($dateTo)->endOfDay()->toDateTimeString() : null;
    }
}
