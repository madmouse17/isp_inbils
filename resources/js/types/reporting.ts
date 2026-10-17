export type MetricUnit =
    'currency' | 'count' | 'percent' | 'minutes' | 'rating' | 'quantity' | 'breakdown' | 'records';

export type MetricDateBasis = 'snapshot' | 'period';

export interface MetricDefinitionData {
    report: string;
    key: string;
    label: string;
    definition: string;
    formula: string;
    unit: MetricUnit;
    date_basis: MetricDateBasis;
    date_column: string | null;
    statuses: string[];
    sources: string[];
    scope: string;
    caveats: string[];
    version: string;
}

/**
 * Version metadata attached to a report response. `MetricKey` lists the definitions a page
 * dereferences directly: the server sends one definition for every key of the report payload,
 * so those keys are required, not optional. Pages that read keys dynamically keep `string`.
 */
export interface ReportMeta<MetricKey extends string = string> {
    report: string;
    formula_version: string | null;
    metrics: Record<MetricKey, MetricDefinitionData>;
}

export interface ReportHubCard {
    key: string;
    title: string;
    description: string;
    href: string;
}

export interface DictionaryReport {
    key: string;
    title: string;
    description: string;
    permission: string;
    formula_version: string | null;
    metrics: MetricDefinitionData[];
}

/** A money figure: a decimal string, or a plain number when the query had nothing to sum. */
export type MetricAmount = string | number;

/** One bucket of a report time series: its ISO start date, an axis label, and one number per metric. */
export interface SeriesPoint {
    bucket: string;
    label: string;
    [metric: string]: number | string;
}

/** Bucket size follows the range: days up to 31 days, ISO weeks up to 184 days, months beyond. */
export interface Series {
    granularity: 'day' | 'week' | 'month';
    points: SeriesPoint[];
}

/** The `charts` prop of the Business report: revenue and MRR per bucket, invoices per status. */
export interface BusinessChartData {
    series: Series;
    invoice_status: Array<{ status: string; count: number }>;
}

/** The `charts` prop of the Sales report: every acquirer, unpaginated, in the table's sort order. */
export interface SalesChartData {
    series: Series;
    acquirers: Array<{ name: string; customers_acquired: number; collected: number }>;
}

/** The `charts` prop of the SLA report: resolved tickets inside and outside their SLA deadline. */
export interface SlaChartData {
    compliance: { compliant: number; breached: number };
}

/** The `charts` prop of the Technician report; null while no technician is selected. */
export interface TechnicianChartData {
    series: Series;
}

/** The payload of `BusinessMetricsQuery`: one company's figures, or the consolidated total. */
export interface BusinessMetrics {
    mrr: MetricAmount;
    active_subscriptions: number;
    suspended_subscriptions: number;
    terminated_subscriptions: number;
    new_subscriptions: number;
    churn: number;
    revenue_paid: MetricAmount;
    recurring_revenue: MetricAmount;
    one_time_revenue: MetricAmount;
    outstanding: MetricAmount;
    asset_distribution: Record<string, number>;
    sla_compliance_pct: number;
    installation_count: number;
}

/** The tickets behind an SLA percentage, so a pooled total can be tied out. */
export interface SlaCounts {
    resolved: number;
    on_time: number;
}

export interface ConsolidatedCompany {
    id: string;
    name: string;
    code: string;
    currency: string;
    timezone: string;
}

export interface ConsolidatedContext {
    mode: 'consolidated';
    head: Pick<ConsolidatedCompany, 'id' | 'name' | 'code' | 'currency'>;
    /** The one currency of every company; null when they differ and the report is refused. */
    currency: string | null;
    /** The Head first, then its active subsidiaries. */
    companies: ConsolidatedCompany[];
}

export interface ConsolidatedRow {
    company_id: string;
    metrics: BusinessMetrics;
    sla: SlaCounts;
}

export interface ConsolidatedReport {
    rows: ConsolidatedRow[];
    total: BusinessMetrics;
    sla: SlaCounts;
}

export interface ConsolidationRefusal {
    code: 'currency_mismatch';
    message: string;
    currencies: string[];
}
