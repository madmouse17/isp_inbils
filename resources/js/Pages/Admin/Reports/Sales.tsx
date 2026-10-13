import type { FormEvent } from 'react';
import { useState } from 'react';
import { usePage } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import {
    DataTable,
    FormulaVersionLabel,
    MetricDefinitionNote,
    NativeSelect,
    PageHeader,
    type DataTableColumn,
} from '@/Components/composite';
import {
    Badge,
    Button,
    Card,
    CardContent,
    CardHeader,
    CardTitle,
    Input,
    TD,
    TR,
} from '@/Components/ui';
import { useCompany } from '@/hooks/useCompany';
import { useServerTable } from '@/hooks/useServerTable';
import { formatMoney, formatNumber } from '@/lib/format';
import { toPagination, type PaginationLike } from '@/lib/pagination';
import type { Company, ReportMeta } from '@/types';

const SALES_METRIC_KEYS = [
    'customers_acquired',
    'subscriptions_activated',
    'active_mrr',
    'invoiced',
    'collected',
    'outstanding',
    'collection_rate',
] as const;

type SalesMetricKey = (typeof SALES_METRIC_KEYS)[number];

interface Metrics {
    customers_acquired: number;
    subscriptions_activated: number;
    active_mrr: string;
    invoiced: string;
    collected: string;
    outstanding: string;
    collection_rate: string | null;
}

interface Row extends Metrics {
    id: number;
    name: string;
    is_active: boolean;
}

interface Acquirer {
    id: number;
    name: string;
    is_active: boolean;
}

interface Props extends Record<string, unknown> {
    data: {
        rows: PaginationLike<Row>;
        unassigned: Metrics | null;
        totals: Metrics;
    };
    acquirers: Acquirer[];
    filters: {
        date_from: string | null;
        date_to: string | null;
        acquirer_id: string | null;
        sort_by: string;
        sort_dir: 'asc' | 'desc';
        per_page: number;
    };
    meta: ReportMeta<SalesMetricKey>;
}

function formatMetric(key: SalesMetricKey, metrics: Metrics, company: Company | null): string {
    switch (key) {
        case 'customers_acquired':
        case 'subscriptions_activated':
            return formatNumber(metrics[key]);
        case 'active_mrr':
        case 'invoiced':
        case 'collected':
        case 'outstanding':
            return formatMoney(metrics[key], company);
        case 'collection_rate':
            return metrics.collection_rate === null ? 'n/a' : `${metrics.collection_rate}%`;
    }
}

function FooterRow({
    label,
    metrics,
    company,
}: {
    label: string;
    metrics: Metrics;
    company: Company | null;
}) {
    return (
        <TR>
            <TD className="font-semibold">{label}</TD>
            {SALES_METRIC_KEYS.map((key) => (
                <TD key={key} className="text-right font-semibold whitespace-nowrap tabular-nums">
                    {formatMetric(key, metrics, company)}
                </TD>
            ))}
        </TR>
    );
}

export default function Sales({ data, acquirers, filters, meta }: Props) {
    const company = useCompany();
    const errors = (usePage().props.errors ?? {}) as Record<string, string | undefined>;
    const table = useServerTable({
        url: route('admin.reports.sales'),
        filters: {
            date_from: filters.date_from,
            date_to: filters.date_to,
            acquirer_id: filters.acquirer_id,
            sort: filters.sort_by,
            direction: filters.sort_dir,
            per_page: filters.per_page,
        },
        only: ['data', 'filters'],
    });
    const [dateFrom, setDateFrom] = useState(filters.date_from ?? '');
    const [dateTo, setDateTo] = useState(filters.date_to ?? '');
    const [acquirerId, setAcquirerId] = useState(filters.acquirer_id ?? '');

    const submit = (e: FormEvent) => {
        e.preventDefault();
        table.visit({ date_from: dateFrom, date_to: dateTo, acquirer_id: acquirerId, page: 1 });
    };

    const columns: DataTableColumn<Row>[] = [
        {
            key: 'name',
            header: 'Acquirer',
            sortable: true,
            cell: (row) => (
                <div className="flex min-w-40 flex-wrap items-center gap-2">
                    <span className="font-medium wrap-break-word">{row.name}</span>
                    {!row.is_active && <Badge variant="secondary">Inactive</Badge>}
                </div>
            ),
        },
        ...SALES_METRIC_KEYS.map((key): DataTableColumn<Row> => ({
            key,
            header: meta.metrics[key].label,
            sortable: true,
            className: 'whitespace-nowrap text-right tabular-nums',
            headerClassName: 'whitespace-nowrap text-right',
            cell: (row) => formatMetric(key, row, company),
        })),
    ];

    const period =
        filters.date_from && filters.date_to
            ? `Period: ${filters.date_from} to ${filters.date_to}, both days included.`
            : 'Period: all time. Set both From and To to limit the period.';

    return (
        <AdminLayout title="KPI Penjualan">
            <div className="space-y-6">
                <PageHeader
                    title="KPI Penjualan"
                    subtitle="Customers acquired, invoiced, collected and outstanding per acquirer."
                    actions={<FormulaVersionLabel version={meta.formula_version} />}
                />
                <Card>
                    <CardContent className="space-y-4 pt-6">
                        <form onSubmit={submit} className="grid gap-2 sm:flex sm:flex-wrap">
                            <div className="min-w-0 sm:w-44">
                                <Input
                                    label="From"
                                    type="date"
                                    className="h-11 min-w-0"
                                    value={dateFrom}
                                    onChange={(e) => setDateFrom(e.target.value)}
                                    error={errors.date_from}
                                />
                            </div>
                            <div className="min-w-0 sm:w-44">
                                <Input
                                    label="To"
                                    type="date"
                                    className="h-11 min-w-0"
                                    value={dateTo}
                                    onChange={(e) => setDateTo(e.target.value)}
                                    error={errors.date_to}
                                />
                            </div>
                            <div className="min-w-0 sm:w-64">
                                <NativeSelect
                                    label="Acquirer"
                                    className="h-11 min-w-0"
                                    value={acquirerId}
                                    onChange={(e) => setAcquirerId(e.target.value)}
                                    error={errors.acquirer_id}
                                    options={[
                                        { value: '', label: 'All acquirers' },
                                        ...acquirers.map((acquirer) => ({
                                            value: String(acquirer.id),
                                            label: acquirer.is_active
                                                ? acquirer.name
                                                : `${acquirer.name} (inactive)`,
                                        })),
                                    ]}
                                />
                            </div>
                            <div className="self-end">
                                <Button
                                    type="submit"
                                    className="h-11 w-full sm:w-auto"
                                    disabled={table.visiting}
                                >
                                    Run
                                </Button>
                            </div>
                        </form>
                        <div className="space-y-1 text-sm text-muted-foreground">
                            <p>{period}</p>
                            <p>
                                Each figure follows the customer&apos;s current acquirer. Correcting
                                an acquirer moves that customer&apos;s whole history, so past
                                periods can change.
                            </p>
                            <p>
                                Collected counts every non-reversed payment by payment date. It does
                                not match Business Metrics Revenue (Paid), which counts fully paid
                                invoices by issue date.
                            </p>
                        </div>
                    </CardContent>
                </Card>
                <section aria-label="Totals">
                    <div className="grid grid-cols-1 items-start gap-4 sm:grid-cols-2 xl:grid-cols-4">
                        {SALES_METRIC_KEYS.map((key) => (
                            <Card key={key} className="min-w-0">
                                <CardHeader>
                                    <CardTitle>{meta.metrics[key].label}</CardTitle>
                                </CardHeader>
                                <CardContent>
                                    <p className="text-2xl font-bold wrap-break-word tabular-nums">
                                        {formatMetric(key, data.totals, company)}
                                    </p>
                                    <MetricDefinitionNote definition={meta.metrics[key]} />
                                </CardContent>
                            </Card>
                        ))}
                    </div>
                </section>
                <Card>
                    <CardContent className="pt-6">
                        <DataTable
                            columns={columns}
                            data={data.rows.data}
                            pagination={toPagination(data.rows)}
                            rowKey="id"
                            sortBy={table.sortBy}
                            sortDir={table.sortDir}
                            onSort={table.onSort}
                            onPageChange={(page) => table.setPage(page)}
                            onPerPageChange={(perPage) => table.setPerPage(perPage)}
                            loading={table.processing}
                            emptyTitle="No acquirers yet"
                            emptyDescription="Customers credited to an employee appear here. Set Acquired by on a customer to start."
                            footer={
                                <>
                                    {data.unassigned && (
                                        <FooterRow
                                            label="Unassigned"
                                            metrics={data.unassigned}
                                            company={company}
                                        />
                                    )}
                                    <FooterRow
                                        label="Total"
                                        metrics={data.totals}
                                        company={company}
                                    />
                                </>
                            }
                        />
                    </CardContent>
                </Card>
            </div>
        </AdminLayout>
    );
}
