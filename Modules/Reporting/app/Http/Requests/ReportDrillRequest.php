<?php

namespace Modules\Reporting\Http\Requests;

use Illuminate\Validation\Rule;
use Modules\Reporting\Drill\DrillRegistry;
use Modules\Reporting\Drill\DrillSource;
use Modules\Reporting\Registry\ReportType;

/**
 * Source rows behind one figure. Allowed with the report's own capability AND the source module's
 * list permission: report access alone never reveals invoices, tickets or subscriptions.
 */
class ReportDrillRequest extends ReportScopedRequest
{
    public function report(): ReportType
    {
        $report = ReportType::tryFrom((string) $this->route('report'));
        abort_unless($report !== null && in_array($report, ReportType::drillable(), true), 404);

        return $report;
    }

    public function source(): DrillSource
    {
        $source = DrillRegistry::find($this->report(), (string) $this->route('metric'));
        abort_if($source === null, 404);

        return $source;
    }

    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null
            && $user->can($this->report()->permission())
            && $this->source()->kind->authorizes($user);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $source = $this->source();
        $sortKeys = Rule::in(array_keys($source->kind->sortable()));

        return [
            ...parent::rules(),
            'sort' => ['nullable', 'string', $sortKeys],
            'sort_by' => ['nullable', 'string', $sortKeys],
            'group' => [$source->grouped ? 'required' : 'nullable', 'string', 'max:190'],
        ];
    }

    public function sort(): ?string
    {
        return $this->input('sort_by') ?? $this->input('sort');
    }

    public function direction(): ?string
    {
        $direction = strtolower((string) ($this->input('sort_dir') ?? $this->input('direction')));

        return $direction === '' ? null : $direction;
    }

    /** Page sizes of the list's "Rows per page" control. */
    public function perPage(): int
    {
        return in_array($this->integer('per_page', 10), [10, 25, 50], true) ? $this->integer('per_page', 10) : 10;
    }
}
