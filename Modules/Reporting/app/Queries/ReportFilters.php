<?php

namespace Modules\Reporting\Queries;

use App\Models\User;
use App\Services\Core\CompanyService;
use LogicException;
use Modules\Reporting\Http\Requests\ReportFilterRequest;
use Modules\Reporting\Registry\ReportType;

/**
 * The filters of one aggregate report as applied on screen. The page, its export and its
 * drill-down lists resolve them once through this object, so all three feed the same inputs to the
 * same predicates and the same company: explicit and fail-closed, never the ambient scope.
 * A record filter is held twice: the integer id the queries filter by, and the public id the links and
 * files print (an integer id never leaves the server).
 */
final readonly class ReportFilters
{
    /** @param  array<string, string>  $publicIds  public ids of the record filters, by query parameter */
    public function __construct(
        public int $companyId,
        public ?string $dateFrom = null,
        public ?string $dateTo = null,
        public ?int $categoryId = null,
        public ?int $locationId = null,
        public ?string $assetType = null,
        public ?int $technicianId = null,
        public ?string $group = null,
        public ?int $packageCategoryId = null,
        public array $publicIds = [],
    ) {}

    public static function fromRequest(ReportFilterRequest $request, ?User $technician = null): self
    {
        $companyId = CompanyService::currentId();
        abort_unless($companyId !== null, 403);

        $category = $request->record('category_id');
        $location = $request->record('location_id');
        $packageCategory = $request->record('service_package_category_id');

        return new self(
            companyId: (int) $companyId,
            dateFrom: $request->input('date_from') ?: null,
            dateTo: $request->input('date_to') ?: null,
            categoryId: $category['id'] ?? null,
            locationId: $location['id'] ?? null,
            assetType: $request->input('asset_type') ?: null,
            technicianId: $technician?->id,
            group: $request->input('group') ?: null,
            packageCategoryId: $packageCategory['id'] ?? null,
            publicIds: array_filter([
                'technician_id' => $technician?->public_id,
                'category_id' => $category['public_id'] ?? null,
                'location_id' => $location['public_id'] ?? null,
                'service_package_category_id' => $packageCategory['public_id'] ?? null,
            ]),
        );
    }

    /**
     * The report's own filters as query parameters, for links that must reproduce this view.
     *
     * @return array<string, string>
     */
    public function query(ReportType $report): array
    {
        $filters = match ($report) {
            ReportType::Business => ['date_from' => $this->dateFrom, 'date_to' => $this->dateTo, 'service_package_category_id' => $this->publicIds['service_package_category_id'] ?? null],
            ReportType::Sla => ['date_from' => $this->dateFrom, 'date_to' => $this->dateTo, 'category_id' => $this->publicIds['category_id'] ?? null],
            ReportType::Asset => [
                'location_id' => $this->publicIds['location_id'] ?? null,
                'asset_type' => $this->assetType,
                'date_from' => $this->dateFrom,
                'date_to' => $this->dateTo,
            ],
            ReportType::Technician => ['technician_id' => $this->publicIds['technician_id'] ?? null, 'date_from' => $this->dateFrom, 'date_to' => $this->dateTo],
            default => [],
        };

        return array_filter($filters, fn (?string $value): bool => $value !== null);
    }

    /**
     * The payload the report shows for these filters: the figures a drill-down list ties out to.
     *
     * @return array<string, mixed>
     */
    public function payload(ReportType $report): array
    {
        return match ($report) {
            ReportType::Business => BusinessMetricsQuery::execute($this->dateFrom, $this->dateTo, $this->companyId, packageCategoryId: $this->packageCategoryId),
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
            ReportType::Business => BusinessMetricsQuery::charts($this->dateFrom, $this->dateTo, $this->companyId, $this->packageCategoryId),
            ReportType::Sla => SlaComplianceQuery::charts($payload),
            ReportType::Technician => TechnicianPerformanceQuery::charts((int) $this->technicianId, $this->dateFrom, $this->dateTo, $this->companyId),
            default => throw new LogicException("{$report->value} has no charts payload."),
        };
    }
}
