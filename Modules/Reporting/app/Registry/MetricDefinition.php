<?php

namespace Modules\Reporting\Registry;

use InvalidArgumentException;

/**
 * One documented figure of a report: what it means, how it is computed and which formula
 * version produced it. A version records the formula exactly as implemented, quirks included.
 * Changing what the query computes REQUIRES a new version (and a characterization test).
 */
final readonly class MetricDefinition
{
    public const INITIAL_VERSION = 'v1';

    /**
     * @param  string  $key  Key of the figure in the report payload (e.g. `mrr`).
     * @param  list<string>  $sources  `table.column` pairs the formula reads.
     * @param  list<string>  $statuses  Record statuses the formula includes; empty when status is irrelevant.
     * @param  list<string>  $caveats  Known quirks of this version, documented rather than fixed.
     */
    public function __construct(
        public ReportType $report,
        public string $key,
        public string $label,
        public string $definition,
        public string $formula,
        public MetricUnit $unit,
        public DateBasis $dateBasis,
        public array $sources,
        public ?string $dateColumn = null,
        public array $statuses = [],
        public string $scope = 'Perusahaan',
        public array $caveats = [],
        public string $version = self::INITIAL_VERSION,
    ) {
        if (preg_match('/^v[1-9]\d*$/', $version) !== 1) {
            throw new InvalidArgumentException("Metric {$report->value}.{$key} has an invalid version [{$version}].");
        }
    }

    /**
     * @return array{
     *     report: string,
     *     key: string,
     *     label: string,
     *     definition: string,
     *     formula: string,
     *     unit: string,
     *     date_basis: string,
     *     date_column: string|null,
     *     statuses: list<string>,
     *     sources: list<string>,
     *     scope: string,
     *     caveats: list<string>,
     *     version: string
     * }
     */
    public function toArray(): array
    {
        return [
            'report' => $this->report->value,
            'key' => $this->key,
            'label' => $this->label,
            'definition' => $this->definition,
            'formula' => $this->formula,
            'unit' => $this->unit->value,
            'date_basis' => $this->dateBasis->value,
            'date_column' => $this->dateColumn,
            'statuses' => $this->statuses,
            'sources' => $this->sources,
            'scope' => $this->scope,
            'caveats' => $this->caveats,
            'version' => $this->version,
        ];
    }
}
