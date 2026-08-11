<?php

namespace Modules\Reporting\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;

class ReportFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('report.view') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'technician_id' => ['nullable', 'integer'],
            'location_id' => ['nullable', 'integer'],
            'asset_type' => ['nullable', 'string'],
            'category_id' => ['nullable', 'integer'],
            'product_id' => ['nullable', 'integer'],
            'user_id' => ['nullable', 'integer'],
            'log_name' => ['nullable', 'string'],
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
