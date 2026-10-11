<?php

namespace Modules\Reporting\Registry;

use Illuminate\Contracts\Auth\Access\Authorizable;

/**
 * Catalog of report surfaces: the single place that maps a report to its route and the
 * capability required to open it. ReportFilterRequest::authorize() and the Reports hub read
 * this map; routes/web.php repeats each capability as `can:` middleware and
 * ReportCapabilityTest fails when the two drift. `report.view` gates the hub only.
 */
enum ReportType: string
{
    case Business = 'business';
    case Sales = 'sales';
    case Technician = 'technician';
    case Asset = 'asset';
    case Sla = 'sla';
    case StockCard = 'stock_card';
    case AuditLog = 'audit_log';
    case Dictionary = 'dictionary';

    public function routeName(): string
    {
        return match ($this) {
            self::Business => 'admin.reports.business',
            self::Sales => 'admin.reports.sales',
            self::Technician => 'admin.reports.technician',
            self::Asset => 'admin.reports.asset',
            self::Sla => 'admin.reports.sla',
            self::StockCard => 'admin.reports.stock-card',
            self::AuditLog => 'admin.reports.audit-log',
            self::Dictionary => 'admin.reports.dictionary',
        };
    }

    public function permission(): string
    {
        return match ($this) {
            self::Business => 'report.business.view',
            self::Sales => 'report.sales.view',
            self::Technician => 'report.technician',
            self::Asset => 'report.asset.view',
            self::Sla => 'report.sla.view',
            self::StockCard => 'report.stock_card.view',
            self::AuditLog => 'report.audit.view',
            self::Dictionary => 'report.dictionary.view',
        };
    }

    public function title(): string
    {
        return match ($this) {
            self::Business => 'Business Metrics',
            self::Sales => 'Sales KPI',
            self::Technician => 'Technician Performance',
            self::Asset => 'Asset Utilization',
            self::Sla => 'SLA Compliance',
            self::StockCard => 'Stock Card',
            self::AuditLog => 'Audit Log',
            self::Dictionary => 'Data Dictionary',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Business => 'MRR, revenue, churn, outstanding, asset utilization',
            self::Sales => 'Customers acquired, invoiced, collected and outstanding per acquirer',
            self::Technician => 'SPK/ticket counts, avg completion, SLA, rating, FRT',
            self::Asset => 'Status distribution, per-location, installation history',
            self::Sla => 'Resolution rate, breach count, per-category breakdown',
            self::StockCard => 'Movement history per product/location',
            self::AuditLog => 'Activity trail by user/module/date',
            self::Dictionary => 'Definition, formula and source columns of every report figure',
        };
    }

    public static function fromRouteName(?string $routeName): ?self
    {
        foreach (self::cases() as $report) {
            if ($report->routeName() === $routeName) {
                return $report;
            }
        }

        return null;
    }

    /**
     * Reports the user may open, in hub order.
     *
     * @return list<self>
     */
    public static function openableBy(?Authorizable $user): array
    {
        if ($user === null) {
            return [];
        }

        return array_values(array_filter(
            self::cases(),
            fn (self $report): bool => $user->can($report->permission()),
        ));
    }

    /** @return array{key: string, title: string, description: string, href: string} */
    public function toCard(): array
    {
        return [
            'key' => $this->value,
            'title' => $this->title(),
            'description' => $this->description(),
            'href' => route($this->routeName(), [], false),
        ];
    }

    /** URL segment of the report: `stock_card` is served at `stock-card`. */
    public function slug(): string
    {
        return str_replace('_', '-', $this->value);
    }

    /** CSV/PDF export of the report; allowed with `report.export` on top of the report's own capability. */
    public function exportRouteName(): string
    {
        return $this->routeName().'.export';
    }

    /** Source-row list behind one figure of the report. */
    public function drillRouteName(): string
    {
        return $this->routeName().'.drill';
    }

    /**
     * Reports with a server-side export. Sales and the Dictionary are outside the export contract.
     *
     * @return list<self>
     */
    public static function exportable(): array
    {
        return [self::Business, self::Technician, self::Asset, self::Sla, self::StockCard, self::AuditLog];
    }

    /**
     * Reports whose figures are aggregates with source rows behind them.
     *
     * @return list<self>
     */
    public static function drillable(): array
    {
        return [self::Business, self::Technician, self::Asset, self::Sla];
    }
}
