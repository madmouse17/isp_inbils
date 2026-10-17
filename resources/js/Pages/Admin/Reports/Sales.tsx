import type { FormEvent } from 'react';
import { useState } from 'react';
import { usePage } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import {
    DataTable,
    FormulaVersionLabel,
    MetricDefinitionNote,
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
    DateRangePicker,
    SearchSelect,
    TD,
    TR,
    type DateRange,
} from '@/Components/ui';
import { useCompany } from '@/hooks/useCompany';
import { useServerTable } from '@/hooks/useServerTable';
import { formatDate, formatMoney, formatNumber } from '@/lib/format';
import { toPagination, type PaginationLike } from '@/lib/pagination';
import type { Company, ReportMeta, SalesChartData } from '@/types';
import { SalesCharts } from './components/SalesCharts';
import { MoneyFigure } from './components/MoneyFigure';

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
    id: string;
    name: string;
    is_active: boolean;
}

interface Acquirer {
    id: string;
    name: string;
    is_active: boolean;
}

interface Props extends Record<string, unknown> {
    data: {
        rows: PaginationLike<Row>;
        unassigned: Metrics | null;
        totals: Metrics;
    };
    charts: SalesChartData;
    acquirers: Acquirer[];
    packageCategories: { id: string; code: string; name: string; is_active: boolean }[];
    filters: {
        date_from: string | null;
        date_to: string | null;
        acquirer_id: string | null;
        service_package_category_id: string | null;
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
            return metrics.collection_rate === null ? '-' : `${metrics.collection_rate}%`;
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

export default function Sales({
    data,
    charts,
    acquirers,
    packageCategories,
    filters,
    meta,
}: Props) {
    const company = useCompany();
    const errors = (usePage().props.errors ?? {}) as Record<string, string | undefined>;
    const table = useServerTable({
        url: route('admin.reports.sales'),
        filters: {
            date_from: filters.date_from,
            date_to: filters.date_to,
            acquirer_id: filters.acquirer_id,
            service_package_category_id: filters.service_package_category_id,
            sort: filters.sort_by,
            direction: filters.sort_dir,
            per_page: filters.per_page,
        },
        only: ['data', 'filters', 'charts'],
    });
    const [range, setRange] = useState<DateRange>({
        from: filters.date_from,
        to: filters.date_to,
    });
    const [acquirerId, setAcquirerId] = useState(filters.acquirer_id ?? '');
    const [categoryId, setCategoryId] = useState(filters.service_package_category_id ?? '');

    const submit = (e: FormEvent) => {
        e.preventDefault();
        table.visit({
            date_from: range.from ?? '',
            date_to: range.to ?? '',
            acquirer_id: acquirerId,
            service_package_category_id: categoryId,
            page: 1,
        });
    };

    const columns: DataTableColumn<Row>[] = [
        {
            key: 'name',
            header: 'Petugas penjualan',
            sortable: true,
            cell: (row) => (
                <div className="flex min-w-40 flex-wrap items-center gap-2">
                    <span className="font-medium wrap-break-word">{row.name}</span>
                    {!row.is_active && <Badge variant="secondary">Nonaktif</Badge>}
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

    const { date_from: from, date_to: to } = filters;
    const period =
        from && to
            ? `${formatDate(from, company)} - ${formatDate(to, company)}`
            : from
              ? `dari ${formatDate(from, company)}`
              : to
                ? `sampai ${formatDate(to, company)}`
                : 'semua waktu';

    return (
        <AdminLayout title="KPI Penjualan">
            <div className="space-y-6">
                <PageHeader
                    title="KPI Penjualan"
                    subtitle="Pelanggan baru, tagihan, pembayaran, dan piutang per petugas penjualan."
                    actions={<FormulaVersionLabel version={meta.formula_version} />}
                />
                <Card>
                    <CardContent className="space-y-4 pt-6">
                        <form onSubmit={submit} className="grid gap-2 sm:flex sm:flex-wrap">
                            <div className="min-w-0 sm:w-80">
                                <DateRangePicker
                                    label="Periode"
                                    value={range}
                                    onChange={setRange}
                                    error={errors.date_from ?? errors.date_to}
                                />
                            </div>
                            <div className="min-w-0 sm:w-64">
                                <SearchSelect
                                    label="Petugas penjualan"
                                    value={acquirerId}
                                    onChange={setAcquirerId}
                                    placeholder="Semua petugas"
                                    error={errors.acquirer_id}
                                    options={acquirers.map((acquirer) => ({
                                        value: String(acquirer.id),
                                        label: acquirer.is_active
                                            ? acquirer.name
                                            : `${acquirer.name} (nonaktif)`,
                                    }))}
                                />
                            </div>
                            <div className="min-w-0 sm:w-64">
                                <SearchSelect
                                    label="Kategori paket"
                                    value={categoryId}
                                    onChange={setCategoryId}
                                    placeholder="Semua kategori"
                                    error={errors.service_package_category_id}
                                    hint="Pelanggan baru tidak terpengaruh."
                                    options={packageCategories.map((category) => ({
                                        value: String(category.id),
                                        label: category.is_active
                                            ? category.name
                                            : `${category.name} (nonaktif)`,
                                    }))}
                                />
                            </div>
                            <div className="self-end">
                                <Button
                                    type="submit"
                                    className="w-full sm:w-auto"
                                    disabled={table.visiting}
                                >
                                    Tampilkan
                                </Button>
                            </div>
                        </form>
                        <div className="space-y-1 text-sm text-muted-foreground">
                            <p>Periode: {period}</p>
                            <p>
                                Setiap angka mengikuti petugas penjualan pelanggan saat ini.
                                Mengoreksi petugas memindahkan seluruh riwayat pelanggan itu, jadi
                                periode yang lalu bisa berubah.
                            </p>
                            <p>
                                Terkumpul menghitung setiap pembayaran yang tidak dibatalkan menurut
                                tanggal bayar. Angkanya tidak sama dengan Pendapatan terbayar di
                                Metrik Bisnis, yang menghitung tagihan lunas menurut tanggal terbit.
                            </p>
                        </div>
                    </CardContent>
                </Card>
                <section aria-label="Total">
                    <div className="grid grid-cols-1 items-start gap-4 sm:grid-cols-2 xl:grid-cols-4">
                        {SALES_METRIC_KEYS.map((key) => (
                            <Card key={key} className="min-w-0">
                                <CardHeader>
                                    <CardTitle>{meta.metrics[key].label}</CardTitle>
                                </CardHeader>
                                <CardContent>
                                    <p className="text-2xl font-bold wrap-break-word tabular-nums">
                                        {key === 'active_mrr' ||
                                        key === 'invoiced' ||
                                        key === 'collected' ||
                                        key === 'outstanding' ? (
                                            <MoneyFigure value={data.totals[key]} />
                                        ) : (
                                            formatMetric(key, data.totals, company)
                                        )}
                                    </p>
                                    <MetricDefinitionNote definition={meta.metrics[key]} />
                                </CardContent>
                            </Card>
                        ))}
                    </div>
                </section>
                <SalesCharts charts={charts} />
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
                            emptyTitle="Belum ada petugas penjualan"
                            emptyDescription="Pelanggan yang dicatat pada seorang karyawan muncul di sini. Isi kolom Diperoleh oleh pada data pelanggan."
                            footer={
                                <>
                                    {data.unassigned && (
                                        <FooterRow
                                            label="Tanpa petugas"
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
