<?php

namespace Modules\Reporting\Http\Requests;

/**
 * File export of a drill-down list: the drill requirements plus `report.export`.
 */
class ReportDrillExportRequest extends ReportDrillRequest
{
    public function authorize(): bool
    {
        return parent::authorize() && ($this->user()?->can('report.export') ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'format' => ['nullable', 'string', 'in:csv,pdf'],
        ];
    }

    public function exportFormat(): string
    {
        return $this->input('format') === 'pdf' ? 'pdf' : 'csv';
    }
}
