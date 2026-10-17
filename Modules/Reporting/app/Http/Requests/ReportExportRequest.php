<?php

namespace Modules\Reporting\Http\Requests;

use Modules\Reporting\Registry\ReportType;

/**
 * Export of a whole report. Allowed with `report.export` AND the report's own capability: neither
 * grants the file alone.
 */
class ReportExportRequest extends ReportScopedRequest
{
    public function report(): ReportType
    {
        $report = ReportType::tryFrom((string) $this->route('report'));
        abort_unless($report !== null && in_array($report, ReportType::exportable(), true), 404);

        return $report;
    }

    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null
            && $user->can('report.export')
            && $user->can($this->report()->permission());
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $report = $this->report();
        $sortColumns = match ($report) {
            ReportType::StockCard => ['nullable', 'string', 'in:created_at,quantity,balance_after'],
            ReportType::AuditLog => ['nullable', 'string', 'in:id,created_at,log_name,event,description'],
            default => ['nullable', 'string', 'max:50'],
        };

        return [
            ...parent::rules(),
            'format' => ['nullable', 'string', 'in:csv,pdf'],
            'sort' => $sortColumns,
            'sort_by' => $sortColumns,
            // The stock card names its product by public id; an unknown or foreign one is a 404 of the ledger, not a form error.
            'product_id' => [$report === ReportType::StockCard ? 'required' : 'nullable', 'ulid'],
        ];
    }

    public function exportFormat(): string
    {
        return $this->input('format') === 'pdf' ? 'pdf' : 'csv';
    }
}
