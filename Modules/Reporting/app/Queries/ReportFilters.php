<?php

namespace Modules\Reporting\Queries;

use App\Services\Core\CompanyService;
use Illuminate\Http\Request;
use LogicException;
use Modules\Reporting\Registry\ReportType;

/**
 * The filters of one aggregate report as applied on screen. The page, its export and its
 * drill-down lists resolve them once through this object, so all three feed the same inputs to the
 * same predicates and the same company: explicit and fail-closed, never the ambient scope.
 */
final readonly class ReportFilters
{
    public function __construct(
        public int $companyId,
        public ?string $dateFrom = null,
        public ?string $dateTo = null,
        public ?int $categoryId = null,
        public ?int $locationId = null,
        public ?string $assetType = null,
        public ?int $technicianId = null,
        public ?string $group = null,
    ) {}

    public static function fromRequest(Request $request, ?int $technicianId = null): self
    {
        $companyId = CompanyService::currentId();
        abort_unless($companyId !== null, 403);

        return new self(
            companyId: (int) $companyId,
            dateFrom: $request->input('date_from') ?: null,
            dateTo: $request->input('date_to') ?: null,
            categoryId: $request->integer('category_id') ?: null,
            locationId: $request->integer('location_id') ?: null,
            assetType: $request->input('asset_type') ?: null,
            technicianId: $technicianId,
            group: $request->input('group') ?: null,
        );
    }

    /**
     * The report's own filters as query parameters, for links that must reproduce this view.
     *
     * @return array<string, string|int>
     */
    public function query(ReportType $report): array
    {
        $filters = match ($report) {
            ReportType::Business => ['date_from' => $this->dateFrom, 'date_to' => $this->dateTo],
            ReportType::Sla => ['date_from' => $this->dateFrom, 'date_to' => $this->dateTo, 'category_id' => $this->categoryId],
            ReportType::Asset => [
                'location_id' => $this->locationId,
                'asset_type' => $this->assetType,
                'date_from' => $this->dateFrom,
                'date_to' => $this->dateTo,
            ],
            ReportType::Technician => ['technician_id' => $this->technicianId, 'date_from' => $this->dateFrom, 'date_to' => $this->dateTo],
            default => [],
        };

        return array_filter($filters, fn (string|int|null $value): bool => $value !== null);
    }

    /**
     * The payload the report shows for these filters: the figures a drill-down list ties out to.
     *
     * @return array<string, mixed>
     */
    public function payload(ReportType $report): array
    {
        return match ($report) {
            ReportType::Business => BusinessMetricsQuery::execute($this->dateFrom, $this->dateTo, $this->companyId),
            ReportType::Sla => SlaComplianceQuery::execute($this->dateFrom, $this->dateTo, $this->categoryId, $this->companyId),
            ReportType::Asset => AssetUtilizationQuery::execute($this->locationId, $this->assetType, $this->dateFrom, $this->dateTo, $this->companyId),
            ReportType::Technician => TechnicianPerformanceQuery::execute((int) $this->technicianId, $this->dateFrom, $this->dateTo, $this->companyId),
            default => throw new LogicException("{$report->value} has no aggregate payload."),
        };
    }

    /**
     * What the page of an aggregate report draws besides its payload: series and splits the figures of the
     * payload do not hold. Kept apart from payload() on purpose: every payload key is a registered metric.
     *
     * @param  array<string, mixed>  $payload  the result of payload() for the same report
     * @return array<string, mixed>
     */
    public function charts(ReportType $report, array $payload): array
    {
        return match ($report) {
            ReportType::Business => BusinessMetricsQuery::charts($this->dateFrom, $this->dateTo, $this->companyId),
            ReportType::Sla => SlaComplianceQuery::charts($payload),
            ReportType::Technician => TechnicianPerformanceQuery::charts((int) $this->technicianId, $this->dateFrom, $this->dateTo, $this->companyId),
            default => throw new LogicException("{$report->value} has no charts payload."),
        };
    }
}
