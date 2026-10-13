<?php

namespace Modules\Reporting\Registry;

/**
 * Code-defined registry of every figure the reports compute, versioned in git.
 *
 * Every entry records the formula EXACTLY as the query implements it today (version v1),
 * including known quirks, so a number can be traced to the definition that produced it.
 * The registry never changes what a query returns: correcting a quirk means shipping a
 * new version of the metric (and its characterization test), never editing a v1 entry
 * in place. Entries are static text; the registry reads no company data.
 */
final class MetricRegistry
{
    /** @var list<MetricDefinition>|null */
    private static ?array $definitions = null;

    /** @return list<MetricDefinition> */
    public static function all(): array
    {
        return self::$definitions ??= [
            ...self::business(),
            ...self::sla(),
            ...self::asset(),
            ...self::technician(),
            ...self::sales(),
            ...self::stockCard(),
        ];
    }

    /** @return list<MetricDefinition> */
    public static function forReport(ReportType $report): array
    {
        return array_values(array_filter(
            self::all(),
            fn (MetricDefinition $metric): bool => $metric->report === $report,
        ));
    }

    /**
     * Report-level formula version: the highest metric version of the report, e.g. `business.v1`.
     * Null for reports that define no metrics.
     */
    public static function formulaVersion(ReportType $report): ?string
    {
        $versions = array_map(
            fn (MetricDefinition $metric): int => (int) substr($metric->version, 1),
            self::forReport($report),
        );

        return $versions === [] ? null : $report->value.'.v'.max($versions);
    }

    /**
     * Version metadata and definitions attached to a report response, keyed by payload key.
     *
     * @return array{
     *     report: string,
     *     formula_version: string|null,
     *     metrics: array<string, array<string, mixed>>
     * }
     */
    public static function meta(ReportType $report): array
    {
        $metrics = [];
        foreach (self::forReport($report) as $metric) {
            $metrics[$metric->key] = $metric->toArray();
        }

        return [
            'report' => $report->value,
            'formula_version' => self::formulaVersion($report),
            'metrics' => $metrics,
        ];
    }

    /**
     * Read-only data dictionary: every report that defines metrics with its capability and definitions.
     *
     * @return list<array<string, mixed>>
     */
    public static function dictionary(): array
    {
        $reports = [];
        foreach (ReportType::cases() as $report) {
            $metrics = self::forReport($report);
            if ($metrics === []) {
                continue;
            }

            $reports[] = [
                'key' => $report->value,
                'title' => $report->title(),
                'description' => $report->description(),
                'permission' => $report->permission(),
                'formula_version' => self::formulaVersion($report),
                'metrics' => array_map(fn (MetricDefinition $metric): array => $metric->toArray(), $metrics),
            ];
        }

        return $reports;
    }

    /** @return list<MetricDefinition> */
    private static function business(): array
    {
        $periodRule = 'The range applies when From, To or both are set (From alone: that day on; To alone: up to that day); otherwise every matching record is used.';

        return [
            new MetricDefinition(
                report: ReportType::Business,
                key: 'mrr',
                label: 'MRR',
                definition: 'Monthly recurring revenue: the monthly charge of every subscription that is active now.',
                formula: 'SUM(service_subscriptions.mrc_amount) for subscriptions with status = active.',
                unit: MetricUnit::Currency,
                dateBasis: DateBasis::Snapshot,
                sources: ['service_subscriptions.mrc_amount', 'service_subscriptions.status'],
                statuses: ['active'],
            ),
            new MetricDefinition(
                report: ReportType::Business,
                key: 'active_subscriptions',
                label: 'Active Subscriptions',
                definition: 'Number of subscriptions that are active now.',
                formula: 'COUNT of service_subscriptions with status = active.',
                unit: MetricUnit::Count,
                dateBasis: DateBasis::Snapshot,
                sources: ['service_subscriptions.status'],
                statuses: ['active'],
            ),
            new MetricDefinition(
                report: ReportType::Business,
                key: 'suspended_subscriptions',
                label: 'Suspended Subscriptions',
                definition: 'Number of subscriptions that are suspended now.',
                formula: 'COUNT of service_subscriptions with status = suspended.',
                unit: MetricUnit::Count,
                dateBasis: DateBasis::Snapshot,
                sources: ['service_subscriptions.status'],
                statuses: ['suspended'],
            ),
            new MetricDefinition(
                report: ReportType::Business,
                key: 'terminated_subscriptions',
                label: 'Terminated Subscriptions',
                definition: 'Number of subscriptions that have been terminated, all time.',
                formula: 'COUNT of service_subscriptions with status = terminated.',
                unit: MetricUnit::Count,
                dateBasis: DateBasis::Snapshot,
                sources: ['service_subscriptions.status'],
                statuses: ['terminated'],
                caveats: ['All-time total; use Churn for terminations inside the selected period.'],
            ),
            new MetricDefinition(
                report: ReportType::Business,
                key: 'new_subscriptions',
                label: 'New Subscriptions',
                definition: 'Subscriptions created during the selected period.',
                formula: 'COUNT of service_subscriptions with created_at between From 00:00:00 and To 23:59:59.',
                unit: MetricUnit::Count,
                dateBasis: DateBasis::Period,
                sources: ['service_subscriptions.created_at'],
                dateColumn: 'service_subscriptions.created_at',
                caveats: [
                    'Returns 0 when neither From nor To is set.',
                    'Counts subscriptions of every status, including ones that are no longer active.',
                ],
            ),
            new MetricDefinition(
                report: ReportType::Business,
                key: 'churn',
                label: 'Churn',
                definition: 'Subscriptions terminated during the selected period. A count, not a rate.',
                formula: 'COUNT of service_subscriptions with status = terminated and terminated_at '
                    .'between From 00:00:00 and To 23:59:59.',
                unit: MetricUnit::Count,
                dateBasis: DateBasis::Period,
                sources: ['service_subscriptions.status', 'service_subscriptions.terminated_at'],
                dateColumn: 'service_subscriptions.terminated_at',
                statuses: ['terminated'],
                caveats: [
                    'A count of terminations, not a percentage: version v1 has no churn-rate denominator.',
                    'Returns 0 when neither From nor To is set.',
                ],
            ),
            new MetricDefinition(
                report: ReportType::Business,
                key: 'revenue_paid',
                label: 'Revenue (Paid)',
                definition: 'Paid amount of fully paid invoices issued during the selected period.',
                formula: 'SUM(invoices.paid_amount) for invoices with status = paid and issue_date '
                    .'between From and To (To inclusive).',
                unit: MetricUnit::Currency,
                dateBasis: DateBasis::Period,
                sources: ['invoices.paid_amount', 'invoices.status', 'invoices.issue_date'],
                dateColumn: 'invoices.issue_date',
                statuses: ['paid'],
                caveats: [
                    'Dated by invoice issue date, not by payment date.',
                    'Partially paid invoices are excluded: the invoice itself must have status paid.',
                    $periodRule,
                ],
            ),
            new MetricDefinition(
                report: ReportType::Business,
                key: 'recurring_revenue',
                label: 'Recurring Revenue',
                definition: 'Part of Revenue (Paid) that comes from recurring invoices.',
                formula: 'SUM(invoices.paid_amount) for the Revenue (Paid) invoices with type = recurring.',
                unit: MetricUnit::Currency,
                dateBasis: DateBasis::Period,
                sources: ['invoices.paid_amount', 'invoices.status', 'invoices.issue_date', 'invoices.type'],
                dateColumn: 'invoices.issue_date',
                statuses: ['paid'],
                caveats: ['Dated by invoice issue date, not by payment date.', $periodRule],
            ),
            new MetricDefinition(
                report: ReportType::Business,
                key: 'one_time_revenue',
                label: 'One-Time Revenue',
                definition: 'Part of Revenue (Paid) that comes from one-time invoices.',
                formula: 'SUM(invoices.paid_amount) for the Revenue (Paid) invoices with type = one_time.',
                unit: MetricUnit::Currency,
                dateBasis: DateBasis::Period,
                sources: ['invoices.paid_amount', 'invoices.status', 'invoices.issue_date', 'invoices.type'],
                dateColumn: 'invoices.issue_date',
                statuses: ['paid'],
                caveats: ['Dated by invoice issue date, not by payment date.', $periodRule],
            ),
            new MetricDefinition(
                report: ReportType::Business,
                key: 'outstanding',
                label: 'Outstanding',
                definition: 'Amount still owed on invoices that were sent and are not fully paid.',
                formula: 'SUM(invoices.total - invoices.paid_amount) for invoices with status sent, partial or overdue.',
                unit: MetricUnit::Currency,
                dateBasis: DateBasis::Snapshot,
                sources: ['invoices.total', 'invoices.paid_amount', 'invoices.status'],
                statuses: ['sent', 'partial', 'overdue'],
            ),
            new MetricDefinition(
                report: ReportType::Business,
                key: 'asset_distribution',
                label: 'Asset Distribution',
                definition: 'Number of network assets in each status.',
                formula: 'COUNT of network_assets grouped by status.',
                unit: MetricUnit::Breakdown,
                dateBasis: DateBasis::Snapshot,
                sources: ['network_assets.status'],
            ),
            new MetricDefinition(
                report: ReportType::Business,
                key: 'sla_compliance_pct',
                label: 'SLA Compliance',
                definition: 'Share of resolved tickets that were resolved on or before their SLA deadline, all time.',
                formula: 'ROUND(100 * COUNT(tickets with resolved_at <= sla_deadline) / COUNT(tickets with resolved_at), 1); '
                    .'0 when no ticket is resolved.',
                unit: MetricUnit::Percent,
                dateBasis: DateBasis::Snapshot,
                sources: ['tickets.resolved_at', 'tickets.sla_deadline'],
                caveats: [
                    'All-time figure: ignores From and To, unlike the SLA Compliance report (sla_rate).',
                    'Tickets without an SLA deadline stay in the denominator but are never compliant.',
                ],
            ),
            new MetricDefinition(
                report: ReportType::Business,
                key: 'installation_count',
                label: 'Installations Completed',
                definition: 'Installation work orders completed during the selected period.',
                formula: 'COUNT of work_orders with type = installation and status = completed and completed_at '
                    .'between From 00:00:00 and To 23:59:59.',
                unit: MetricUnit::Count,
                dateBasis: DateBasis::Period,
                sources: ['work_orders.type', 'work_orders.status', 'work_orders.completed_at'],
                dateColumn: 'work_orders.completed_at',
                statuses: ['completed'],
                caveats: [
                    $periodRule,
                    'Counts work orders, unlike Asset Utilization installation_count, which counts asset installation records.',
                ],
            ),
        ];
    }

    /** @return list<MetricDefinition> */
    private static function sales(): array
    {
        $scope = 'Company, per acquirer';
        $periodRule = 'The range applies when From, To or both are set (From alone: that day on; To alone: up to that day); otherwise every matching record is used.';
        $attribution = 'Follows the current acquirer of each customer: correcting an acquirer moves that customer\'s whole history '
            .'to the new acquirer, so figures of past periods change. Customers without an acquirer, or whose acquirer is not a '
            .'user of the company, are reported as Unassigned.';

        return [
            new MetricDefinition(
                report: ReportType::Sales,
                key: 'customers_acquired',
                label: 'Customers Acquired',
                definition: 'Customers credited to the acquirer that were created during the selected period.',
                formula: 'COUNT of customers with acquired_by_user_id = the acquirer, deleted_at empty and created_at '
                    .'between From 00:00:00 and To 23:59:59.',
                unit: MetricUnit::Count,
                dateBasis: DateBasis::Period,
                sources: ['customers.acquired_by_user_id', 'customers.created_at', 'customers.deleted_at'],
                dateColumn: 'customers.created_at',
                scope: $scope,
                caveats: [
                    $attribution,
                    'Dated by the customer creation date, not by the day an acquirer was assigned: imported or back-filled '
                        .'customers count on the day they were created.',
                    'Soft-deleted customers are not counted.',
                    $periodRule,
                ],
            ),
            new MetricDefinition(
                report: ReportType::Sales,
                key: 'subscriptions_activated',
                label: 'Subscriptions Activated',
                definition: 'Subscriptions of the acquirer customers that were activated during the selected period.',
                formula: 'COUNT of service_subscriptions of the customers credited to the acquirer with activation_date '
                    .'between From and To (To inclusive).',
                unit: MetricUnit::Count,
                dateBasis: DateBasis::Period,
                sources: [
                    'customers.acquired_by_user_id',
                    'service_subscriptions.customer_id',
                    'service_subscriptions.activation_date',
                ],
                dateColumn: 'service_subscriptions.activation_date',
                scope: $scope,
                caveats: [
                    $attribution,
                    'Counts subscriptions of every status that have an activation date: suspended and terminated ones still count.',
                    'Subscriptions without an activation date (pending) are never counted.',
                    'Subscriptions of soft-deleted customers still count; soft-deleted subscriptions never do.',
                    $periodRule,
                ],
            ),
            new MetricDefinition(
                report: ReportType::Sales,
                key: 'active_mrr',
                label: 'Active MRR',
                definition: 'Monthly recurring revenue of the acquirer customers: the monthly charge of every subscription '
                    .'that is active now.',
                formula: 'SUM(service_subscriptions.mrc_amount) for subscriptions of the customers credited to the acquirer '
                    .'with status = active.',
                unit: MetricUnit::Currency,
                dateBasis: DateBasis::Snapshot,
                sources: [
                    'customers.acquired_by_user_id',
                    'service_subscriptions.mrc_amount',
                    'service_subscriptions.status',
                ],
                statuses: ['active'],
                scope: $scope,
                caveats: [
                    $attribution,
                    'Subscriptions of soft-deleted customers still count; soft-deleted subscriptions never do.',
                ],
            ),
            new MetricDefinition(
                report: ReportType::Sales,
                key: 'invoiced',
                label: 'Invoiced',
                definition: 'Amount invoiced to the acquirer customers during the selected period, draft and cancelled '
                    .'invoices excluded.',
                formula: 'SUM(invoices.total) for invoices of the customers credited to the acquirer with status sent, '
                    .'partial, paid or overdue and issue_date between From and To (To inclusive).',
                unit: MetricUnit::Currency,
                dateBasis: DateBasis::Period,
                sources: [
                    'customers.acquired_by_user_id',
                    'invoices.customer_id',
                    'invoices.total',
                    'invoices.status',
                    'invoices.issue_date',
                ],
                dateColumn: 'invoices.issue_date',
                statuses: ['sent', 'partial', 'paid', 'overdue'],
                scope: $scope,
                caveats: [
                    $attribution,
                    'Dated by invoice issue date, not by payment date.',
                    'Draft, cancelled and soft-deleted invoices are never counted; invoices of soft-deleted customers still are.',
                    $periodRule,
                ],
            ),
            new MetricDefinition(
                report: ReportType::Sales,
                key: 'collected',
                label: 'Collected',
                definition: 'Cash received from the acquirer customers during the selected period: every payment that was '
                    .'not reversed.',
                formula: 'SUM(payments.amount) for payments with cancelled_at empty and paid_at between From 00:00:00 and '
                    .'To 23:59:59, on invoices of the customers credited to the acquirer.',
                unit: MetricUnit::Currency,
                dateBasis: DateBasis::Period,
                sources: [
                    'customers.acquired_by_user_id',
                    'invoices.customer_id',
                    'payments.invoice_id',
                    'payments.amount',
                    'payments.paid_at',
                    'payments.cancelled_at',
                ],
                dateColumn: 'payments.paid_at',
                scope: $scope,
                caveats: [
                    $attribution,
                    'Dated by payment date, not by invoice issue date: a payment can settle an invoice issued before the range.',
                    'Reversed payments never count, whatever date they were reversed.',
                    'Payments of soft-deleted invoices never count; payments of invoices of soft-deleted customers still do.',
                    'Does not match Business Metrics Revenue (Paid), which sums paid_amount of fully paid invoices by issue date '
                        .'and ignores partial payments.',
                    $periodRule,
                ],
            ),
            new MetricDefinition(
                report: ReportType::Sales,
                key: 'outstanding',
                label: 'Outstanding',
                definition: 'Amount the acquirer customers still owe on invoices that were sent and are not fully paid.',
                formula: 'SUM(invoices.total - invoices.paid_amount) for invoices of the customers credited to the acquirer '
                    .'with status sent, partial or overdue.',
                unit: MetricUnit::Currency,
                dateBasis: DateBasis::Snapshot,
                sources: [
                    'customers.acquired_by_user_id',
                    'invoices.customer_id',
                    'invoices.total',
                    'invoices.paid_amount',
                    'invoices.status',
                ],
                statuses: ['sent', 'partial', 'overdue'],
                scope: $scope,
                caveats: [
                    $attribution,
                    'Draft, cancelled and soft-deleted invoices are never outstanding; invoices of soft-deleted customers still are.',
                    'Summed over every acquirer and Unassigned it equals Business Metrics Outstanding.',
                ],
            ),
            new MetricDefinition(
                report: ReportType::Sales,
                key: 'collection_rate',
                label: 'Collection Rate',
                definition: 'Collected as a percentage of Invoiced in the selected period.',
                formula: 'ROUND(100 * Collected / Invoiced, 2); empty when Invoiced is 0.',
                unit: MetricUnit::Percent,
                dateBasis: DateBasis::Period,
                sources: [
                    'invoices.total',
                    'invoices.status',
                    'invoices.issue_date',
                    'payments.amount',
                    'payments.paid_at',
                    'payments.cancelled_at',
                ],
                dateColumn: 'payments.paid_at',
                statuses: ['sent', 'partial', 'paid', 'overdue'],
                scope: $scope,
                caveats: [
                    $attribution,
                    'Collected is dated by payment date and Invoiced by issue date, so they cover different invoices and the '
                        .'rate can exceed 100 percent.',
                    'Empty when Invoiced is 0, even if payments arrived in the period.',
                    $periodRule,
                ],
            ),
        ];
    }

    /** @return list<MetricDefinition> */
    private static function sla(): array
    {
        $periodRule = 'The range applies when From, To or both are set (From alone: that day on; To alone: up to that day); otherwise all resolved tickets are used.';

        return [
            new MetricDefinition(
                report: ReportType::Sla,
                key: 'total_resolved',
                label: 'Total Resolved',
                definition: 'Resolved tickets in the selected period and category.',
                formula: 'COUNT of tickets with resolved_at set, resolved_at between From 00:00:00 and To 23:59:59, '
                    .'and category_id = the chosen category when one is chosen.',
                unit: MetricUnit::Count,
                dateBasis: DateBasis::Period,
                sources: ['tickets.resolved_at', 'tickets.category_id'],
                dateColumn: 'tickets.resolved_at',
                caveats: [$periodRule],
            ),
            new MetricDefinition(
                report: ReportType::Sla,
                key: 'sla_compliant',
                label: 'SLA Compliant',
                definition: 'Resolved tickets that met their SLA deadline.',
                formula: 'COUNT of the Total Resolved tickets with resolved_at <= sla_deadline.',
                unit: MetricUnit::Count,
                dateBasis: DateBasis::Period,
                sources: ['tickets.resolved_at', 'tickets.sla_deadline', 'tickets.category_id'],
                dateColumn: 'tickets.resolved_at',
                caveats: ['Tickets without an SLA deadline are never compliant.', $periodRule],
            ),
            new MetricDefinition(
                report: ReportType::Sla,
                key: 'sla_rate',
                label: 'SLA Rate',
                definition: 'Percentage of resolved tickets that met their SLA deadline.',
                formula: 'ROUND(100 * SLA Compliant / Total Resolved, 1); 0 when Total Resolved is 0.',
                unit: MetricUnit::Percent,
                dateBasis: DateBasis::Period,
                sources: ['tickets.resolved_at', 'tickets.sla_deadline', 'tickets.category_id'],
                dateColumn: 'tickets.resolved_at',
                caveats: [
                    'Tickets without an SLA deadline stay in the denominator but are never compliant.',
                    'Follows the selected period; Business Metrics SLA Compliance is all-time and can differ.',
                    $periodRule,
                ],
            ),
            new MetricDefinition(
                report: ReportType::Sla,
                key: 'breach_count',
                label: 'Breaches',
                definition: 'Resolved tickets that missed their SLA deadline.',
                formula: 'COUNT of the Total Resolved tickets with resolved_at > sla_deadline.',
                unit: MetricUnit::Count,
                dateBasis: DateBasis::Period,
                sources: ['tickets.resolved_at', 'tickets.sla_deadline', 'tickets.category_id'],
                dateColumn: 'tickets.resolved_at',
                caveats: [
                    'Tickets without an SLA deadline are neither compliant nor breached, '
                        .'so SLA Compliant plus Breaches can be lower than Total Resolved.',
                    $periodRule,
                ],
            ),
            new MetricDefinition(
                report: ReportType::Sla,
                key: 'response_on_time',
                label: 'Response On Time',
                definition: 'Resolved tickets whose first response came within their response target.',
                formula: 'COUNT of the Total Resolved tickets with first_response_at <= response_deadline.',
                unit: MetricUnit::Count,
                dateBasis: DateBasis::Period,
                sources: ['tickets.resolved_at', 'tickets.first_response_at', 'tickets.response_deadline', 'tickets.category_id'],
                dateColumn: 'tickets.resolved_at',
                caveats: [
                    'Tickets without a response target are never counted here; see No Response Target.',
                    'A resolved ticket with a target but no recorded first response is neither on time nor late.',
                    $periodRule,
                ],
            ),
            new MetricDefinition(
                report: ReportType::Sla,
                key: 'response_breached',
                label: 'Response Breaches',
                definition: 'Resolved tickets whose first response came after their response target.',
                formula: 'COUNT of the Total Resolved tickets with first_response_at > response_deadline.',
                unit: MetricUnit::Count,
                dateBasis: DateBasis::Period,
                sources: ['tickets.resolved_at', 'tickets.first_response_at', 'tickets.response_deadline', 'tickets.category_id'],
                dateColumn: 'tickets.resolved_at',
                caveats: [
                    'Tickets without a response target are never counted here; see No Response Target.',
                    'A resolved ticket with a target but no recorded first response is neither on time nor late.',
                    $periodRule,
                ],
            ),
            new MetricDefinition(
                report: ReportType::Sla,
                key: 'response_without_target',
                label: 'No Response Target',
                definition: 'Resolved tickets created without a response target.',
                formula: 'COUNT of the Total Resolved tickets with response_deadline empty.',
                unit: MetricUnit::Count,
                dateBasis: DateBasis::Period,
                sources: ['tickets.resolved_at', 'tickets.response_deadline', 'tickets.category_id'],
                dateColumn: 'tickets.resolved_at',
                caveats: [
                    'Every ticket created before response targets existed has none, so it stays out of Response On Time, '
                        .'Response Breaches and Response Rate.',
                    $periodRule,
                ],
            ),
            new MetricDefinition(
                report: ReportType::Sla,
                key: 'response_rate',
                label: 'Response Rate',
                definition: 'Percentage of judged resolved tickets whose first response came within their response target.',
                formula: 'ROUND(100 * Response On Time / (Response On Time + Response Breaches), 1); empty when both are 0.',
                unit: MetricUnit::Percent,
                dateBasis: DateBasis::Period,
                sources: ['tickets.resolved_at', 'tickets.first_response_at', 'tickets.response_deadline', 'tickets.category_id'],
                dateColumn: 'tickets.resolved_at',
                caveats: [
                    'Tickets without a response target or without a recorded first response are not judged and stay out of '
                        .'the denominator, unlike SLA Rate.',
                    'Empty when no resolved ticket has been judged.',
                    $periodRule,
                ],
            ),
            new MetricDefinition(
                report: ReportType::Sla,
                key: 'avg_resolution_minutes',
                label: 'Avg Resolution',
                definition: 'Average minutes from ticket creation to resolution.',
                formula: 'ROUND(AVG(TIMESTAMPDIFF(MINUTE, created_at, resolved_at))) over the Total Resolved tickets.',
                unit: MetricUnit::Minutes,
                dateBasis: DateBasis::Period,
                sources: ['tickets.created_at', 'tickets.resolved_at', 'tickets.category_id'],
                dateColumn: 'tickets.resolved_at',
                caveats: ['Empty when there are no matching tickets.', $periodRule],
            ),
            new MetricDefinition(
                report: ReportType::Sla,
                key: 'avg_frt_minutes',
                label: 'Avg First Response',
                definition: 'Average minutes from ticket creation to the first response.',
                formula: 'ROUND(AVG(TIMESTAMPDIFF(MINUTE, created_at, first_response_at))) over the Total Resolved '
                    .'tickets that have first_response_at.',
                unit: MetricUnit::Minutes,
                dateBasis: DateBasis::Period,
                sources: ['tickets.created_at', 'tickets.first_response_at', 'tickets.category_id'],
                dateColumn: 'tickets.resolved_at',
                caveats: [
                    'Tickets without a first response are skipped.',
                    'Empty when no matching ticket has a first response.',
                    $periodRule,
                ],
            ),
            new MetricDefinition(
                report: ReportType::Sla,
                key: 'by_category',
                label: 'Resolved by Category',
                definition: 'Resolved tickets and SLA compliance for each ticket category.',
                formula: 'Per ticket_categories.name: COUNT of tickets with resolved_at set, COUNT of those with '
                    .'resolved_at <= sla_deadline, and rate = ROUND(100 * compliant / total, 1).',
                unit: MetricUnit::Breakdown,
                dateBasis: DateBasis::Snapshot,
                sources: [
                    'tickets.category_id',
                    'tickets.resolved_at',
                    'tickets.sla_deadline',
                    'ticket_categories.name',
                ],
                caveats: [
                    'Ignores both the From/To filter and the category filter, so it can disagree with the figures '
                        .'above it for the same period.',
                    'Categories that share a name are merged into one row.',
                ],
            ),
        ];
    }

    /** @return list<MetricDefinition> */
    private static function asset(): array
    {
        return [
            new MetricDefinition(
                report: ReportType::Asset,
                key: 'status_distribution',
                label: 'Status Distribution',
                definition: 'Number of network assets in each status.',
                formula: 'COUNT of network_assets grouped by status, limited by the Asset Type and location filters.',
                unit: MetricUnit::Breakdown,
                dateBasis: DateBasis::Snapshot,
                sources: [
                    'network_assets.status',
                    'network_assets.asset_type',
                    'network_assets.location_id',
                ],
            ),
            new MetricDefinition(
                report: ReportType::Asset,
                key: 'by_location',
                label: 'Assets by Location',
                definition: 'Number of network assets at each location.',
                formula: 'COUNT of network_assets that have a location, grouped by locations.name.',
                unit: MetricUnit::Breakdown,
                dateBasis: DateBasis::Snapshot,
                sources: ['network_assets.location_id', 'locations.name'],
                caveats: [
                    'Ignores the Asset Type and location filters.',
                    'Locations that share a name are merged into one row.',
                ],
            ),
            new MetricDefinition(
                report: ReportType::Asset,
                key: 'installation_count',
                label: 'Installations',
                definition: 'Asset installations recorded during the selected period.',
                formula: 'COUNT of network_asset_installations with installed_at between From 00:00:00 and To 23:59:59.',
                unit: MetricUnit::Count,
                dateBasis: DateBasis::Period,
                sources: ['network_asset_installations.installed_at'],
                dateColumn: 'network_asset_installations.installed_at',
                caveats: [
                    'The range applies when From, To or both are set (From alone: that day on; To alone: up to that day); otherwise every installation is counted.',
                    'Ignores the Asset Type and location filters.',
                    'Counts asset installation records, unlike Business Metrics installation_count, which counts '
                        .'completed work orders.',
                ],
            ),
            new MetricDefinition(
                report: ReportType::Asset,
                key: 'customer_linked_count',
                label: 'Customer-Linked',
                definition: 'Number of network assets assigned to a customer.',
                formula: 'COUNT of network_assets where customer_id is not null.',
                unit: MetricUnit::Count,
                dateBasis: DateBasis::Snapshot,
                sources: ['network_assets.customer_id'],
                caveats: ['Ignores all filters.'],
            ),
            new MetricDefinition(
                report: ReportType::Asset,
                key: 'retired_count',
                label: 'Retired',
                definition: 'Number of network assets that are retired now.',
                formula: 'COUNT of network_assets with status = retired.',
                unit: MetricUnit::Count,
                dateBasis: DateBasis::Snapshot,
                sources: ['network_assets.status'],
                statuses: ['retired'],
                caveats: [
                    'A running total, not the assets retired during the selected period.',
                    'Ignores all filters.',
                ],
            ),
        ];
    }

    /** @return list<MetricDefinition> */
    private static function technician(): array
    {
        $scope = 'Company, selected technician';
        $periodRule = 'The range applies when From, To or both are set (From alone: that day on; To alone: up to that day); otherwise all matching records are used.';

        return [
            new MetricDefinition(
                report: ReportType::Technician,
                key: 'spk_completed',
                label: 'SPK Completed',
                definition: 'Work orders (SPK) the technician completed during the selected period.',
                formula: 'COUNT of work_orders assigned to the technician with status = completed and completed_at '
                    .'between From 00:00:00 and To 23:59:59.',
                unit: MetricUnit::Count,
                dateBasis: DateBasis::Period,
                sources: ['work_orders.assigned_to', 'work_orders.status', 'work_orders.completed_at'],
                dateColumn: 'work_orders.completed_at',
                statuses: ['completed'],
                scope: $scope,
                caveats: [$periodRule],
            ),
            new MetricDefinition(
                report: ReportType::Technician,
                key: 'tickets_resolved',
                label: 'Tickets Resolved',
                definition: 'Tickets the technician resolved or closed during the selected period.',
                formula: 'COUNT of tickets assigned to the technician with status resolved or closed and resolved_at '
                    .'between From 00:00:00 and To 23:59:59.',
                unit: MetricUnit::Count,
                dateBasis: DateBasis::Period,
                sources: ['tickets.assigned_to', 'tickets.status', 'tickets.resolved_at'],
                dateColumn: 'tickets.resolved_at',
                statuses: ['resolved', 'closed'],
                scope: $scope,
                caveats: [$periodRule],
            ),
            new MetricDefinition(
                report: ReportType::Technician,
                key: 'avg_spk_minutes',
                label: 'Avg SPK Time',
                definition: 'Average minutes from start to completion of the completed work orders.',
                formula: 'ROUND(AVG(TIMESTAMPDIFF(MINUTE, started_at, completed_at))) over the SPK Completed work orders '
                    .'that have started_at.',
                unit: MetricUnit::Minutes,
                dateBasis: DateBasis::Period,
                sources: ['work_orders.started_at', 'work_orders.completed_at'],
                dateColumn: 'work_orders.completed_at',
                statuses: ['completed'],
                scope: $scope,
                caveats: [
                    'Work orders without started_at are skipped.',
                    'Empty when no matching work order has started_at.',
                    $periodRule,
                ],
            ),
            new MetricDefinition(
                report: ReportType::Technician,
                key: 'avg_ticket_minutes',
                label: 'Avg Ticket Time',
                definition: 'Average minutes from creation to resolution of the resolved tickets.',
                formula: 'ROUND(AVG(TIMESTAMPDIFF(MINUTE, created_at, resolved_at))) over the Tickets Resolved '
                    .'that have resolved_at.',
                unit: MetricUnit::Minutes,
                dateBasis: DateBasis::Period,
                sources: ['tickets.created_at', 'tickets.resolved_at'],
                dateColumn: 'tickets.resolved_at',
                statuses: ['resolved', 'closed'],
                scope: $scope,
                caveats: ['Empty when there are no matching tickets.', $periodRule],
            ),
            new MetricDefinition(
                report: ReportType::Technician,
                key: 'sla_compliance_pct',
                label: 'SLA Compliance',
                definition: 'Share of the technician resolved tickets that met their SLA deadline.',
                formula: 'ROUND(100 * COUNT(Tickets Resolved with resolved_at <= sla_deadline) / COUNT(Tickets Resolved), 1); '
                    .'0 when there are none.',
                unit: MetricUnit::Percent,
                dateBasis: DateBasis::Period,
                sources: ['tickets.resolved_at', 'tickets.sla_deadline', 'tickets.status'],
                dateColumn: 'tickets.resolved_at',
                statuses: ['resolved', 'closed'],
                scope: $scope,
                caveats: [
                    'Counts tickets with status resolved or closed; the SLA Compliance report counts every ticket '
                        .'with resolved_at, so the two can differ.',
                    'Tickets without an SLA deadline stay in the denominator but are never compliant.',
                    $periodRule,
                ],
            ),
            new MetricDefinition(
                report: ReportType::Technician,
                key: 'avg_frt_minutes',
                label: 'Avg First Response',
                definition: 'Average minutes from ticket creation to the first response.',
                formula: 'ROUND(AVG(TIMESTAMPDIFF(MINUTE, created_at, first_response_at))) over the Tickets Resolved '
                    .'that have first_response_at.',
                unit: MetricUnit::Minutes,
                dateBasis: DateBasis::Period,
                sources: ['tickets.created_at', 'tickets.first_response_at'],
                dateColumn: 'tickets.resolved_at',
                statuses: ['resolved', 'closed'],
                scope: $scope,
                caveats: [
                    'Tickets without a first response are skipped.',
                    'Empty when no matching ticket has a first response.',
                    $periodRule,
                ],
            ),
            new MetricDefinition(
                report: ReportType::Technician,
                key: 'avg_score',
                label: 'Avg Evaluation Score',
                definition: 'Average evaluation score the technician received during the selected period.',
                formula: 'ROUND(AVG(employee_evaluations.score), 1) for evaluations of the technician with evaluated_at '
                    .'between From 00:00:00 and To 23:59:59.',
                unit: MetricUnit::Rating,
                dateBasis: DateBasis::Period,
                sources: [
                    'employee_evaluations.employee_id',
                    'employee_evaluations.score',
                    'employee_evaluations.evaluated_at',
                ],
                dateColumn: 'employee_evaluations.evaluated_at',
                scope: $scope,
                caveats: ['Empty when there are no evaluations in the selected period.', $periodRule],
            ),
            new MetricDefinition(
                report: ReportType::Technician,
                key: 'avg_customer_rating',
                label: 'Avg Customer Rating',
                definition: 'Average customer rating in the technician evaluations of the selected period.',
                formula: 'ROUND(AVG(employee_evaluations.customer_rating), 1) over the evaluations of the technician '
                    .'where customer_rating is set.',
                unit: MetricUnit::Rating,
                dateBasis: DateBasis::Period,
                sources: [
                    'employee_evaluations.employee_id',
                    'employee_evaluations.customer_rating',
                    'employee_evaluations.evaluated_at',
                ],
                dateColumn: 'employee_evaluations.evaluated_at',
                scope: $scope,
                caveats: [
                    'Evaluations without a customer rating are skipped.',
                    'Empty when no evaluation in the selected period has a customer rating.',
                    $periodRule,
                ],
            ),
            new MetricDefinition(
                report: ReportType::Technician,
                key: 'active_workload',
                label: 'Active Workload',
                definition: 'Open work the technician is carrying right now.',
                formula: 'COUNT of work_orders assigned to the technician with status assigned or in_progress, plus '
                    .'COUNT of tickets assigned to the technician with status open, assigned or on_progress.',
                unit: MetricUnit::Count,
                dateBasis: DateBasis::Snapshot,
                sources: [
                    'work_orders.assigned_to',
                    'work_orders.status',
                    'tickets.assigned_to',
                    'tickets.status',
                ],
                statuses: ['assigned', 'in_progress', 'open', 'on_progress'],
                scope: $scope,
            ),
            new MetricDefinition(
                report: ReportType::Technician,
                key: 'spk_by_type',
                label: 'SPK by Type',
                definition: 'Work orders completed during the selected period, grouped by type.',
                formula: 'COUNT of work_orders assigned to the technician with status = completed and completed_at in '
                    .'the range, grouped by work_orders.type.',
                unit: MetricUnit::Breakdown,
                dateBasis: DateBasis::Period,
                sources: ['work_orders.assigned_to', 'work_orders.status', 'work_orders.type', 'work_orders.completed_at'],
                dateColumn: 'work_orders.completed_at',
                statuses: ['completed'],
                scope: $scope,
                caveats: [$periodRule],
            ),
            new MetricDefinition(
                report: ReportType::Technician,
                key: 'tickets_by_category',
                label: 'Tickets by Category',
                definition: 'Tickets resolved or closed during the selected period, grouped by category.',
                formula: 'COUNT of tickets assigned to the technician with status resolved or closed and resolved_at in '
                    .'the range, grouped by ticket_categories.name.',
                unit: MetricUnit::Breakdown,
                dateBasis: DateBasis::Period,
                sources: [
                    'tickets.assigned_to',
                    'tickets.status',
                    'tickets.resolved_at',
                    'tickets.category_id',
                    'ticket_categories.name',
                ],
                dateColumn: 'tickets.resolved_at',
                statuses: ['resolved', 'closed'],
                scope: $scope,
                caveats: ['Categories that share a name are merged into one row.', $periodRule],
            ),
        ];
    }

    /** @return list<MetricDefinition> */
    private static function stockCard(): array
    {
        $scope = 'Company, selected product';

        return [
            new MetricDefinition(
                report: ReportType::StockCard,
                key: 'movements',
                label: 'Stock Movements',
                definition: 'Ledger of the recorded stock movements of the selected product.',
                formula: 'Rows of stock_movements for the product, optionally limited to a location (as source or '
                    .'destination) and to created_at between From 00:00:00 and To 23:59:59; sorted and paged by the server.',
                unit: MetricUnit::Records,
                dateBasis: DateBasis::Period,
                sources: [
                    'stock_movements.product_id',
                    'stock_movements.from_location_id',
                    'stock_movements.to_location_id',
                    'stock_movements.created_at',
                ],
                dateColumn: 'stock_movements.created_at',
                scope: $scope,
                caveats: [
                    'Lists recorded movements only: there is no opening balance or reconciliation row.',
                    'The range applies when From, To or both are set (From alone: that day on; To alone: up to that day); otherwise every movement is listed.',
                ],
            ),
            new MetricDefinition(
                report: ReportType::StockCard,
                key: 'quantity',
                label: 'Qty',
                definition: 'Signed quantity of one movement exactly as Inventory recorded it.',
                formula: 'stock_movements.quantity, shown as stored.',
                unit: MetricUnit::Quantity,
                dateBasis: DateBasis::Period,
                sources: ['stock_movements.quantity'],
                dateColumn: 'stock_movements.created_at',
                scope: $scope,
            ),
            new MetricDefinition(
                report: ReportType::StockCard,
                key: 'balance_after',
                label: 'Balance',
                definition: 'Quantity on hand of the stock row the movement touched (product at a location) right after '
                    .'the movement.',
                formula: 'stock_movements.balance_after, shown as stored; the report does not recompute it.',
                unit: MetricUnit::Quantity,
                dateBasis: DateBasis::Period,
                sources: ['stock_movements.balance_after'],
                dateColumn: 'stock_movements.created_at',
                scope: $scope,
                caveats: [
                    'Stored when Inventory recorded the movement and never recalculated by this report.',
                    'A balance per stock row, not a running total across locations or across the filtered rows.',
                ],
            ),
        ];
    }
}
