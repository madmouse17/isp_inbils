import type { FormEvent } from 'react';
import { useState } from 'react';
import { Link, router, usePage } from '@inertiajs/react';
import { AlertTriangle } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import {
    DataTable,
    FormulaVersionLabel,
    MetricDefinitionNote,
    PageHeader,
    LoadingState,
    type DataTableColumn,
} from '@/Components/composite';
import {
    Alert,
    Badge,
    Button,
    Card,
    CardContent,
    DateRangePicker,
    ExportMenu,
    type DateRange,
    TD,
    TR,
} from '@/Components/ui';
import { usePermission } from '@/hooks/usePermission';
import { useVisitPending } from '@/hooks/useVisitPending';
import { formatDate, formatMoney, formatNumber, formatPercent } from '@/lib/format';
import { cn } from '@/lib/utils';
import type {
    BusinessMetrics,
    ConsolidatedCompany,
    ConsolidatedContext,
    ConsolidatedReport,
    ConsolidationRefusal,
    PageProps,
    ReportMeta,
    SlaCounts,
} from '@/types';

// The six figures the Business page shows; the CSV export holds every Business metric.
const METRIC_KEYS = [
    'mrr',
    'revenue_paid',
    'outstanding',
    'active_subscriptions',
    'suspended_subscriptions',
    'sla_compliance_pct',
] as const;

type MetricKey = (typeof METRIC_KEYS)[number];

interface Props extends Record<string, unknown> {
    context: ConsolidatedContext;
    /** Null exactly when `refusal` is set. */
    data: ConsolidatedReport | null;
    refusal: ConsolidationRefusal | null;
    filters: { date_from?: string | null; date_to?: string | null };
    meta: ReportMeta<MetricKey>;
    can: { export: boolean };
}

interface CompanyRow {
    company: ConsolidatedCompany;
    metrics: BusinessMetrics;
    sla: SlaCounts;
}

interface MetricValueProps {
    metric: MetricKey;
    metrics: BusinessMetrics;
    sla: SlaCounts;
    currency: string;
}

function MetricValue({ metric, metrics, sla, currency }: MetricValueProps) {
    switch (metric) {
        case 'mrr':
        case 'revenue_paid':
        case 'outstanding':
            return <>{formatMoney(metrics[metric], currency)}</>;
        case 'active_subscriptions':
        case 'suspended_subscriptions':
            return <>{formatNumber(metrics[metric])}</>;
        case 'sla_compliance_pct':
            return (
                <>
                    {formatPercent(metrics.sla_compliance_pct)}
                    <span className="block text-xs font-normal text-muted-foreground">
                        {sla.on_time} dari {sla.resolved} tepat waktu
                    </span>
                </>
            );
    }
}

interface CompanyLabelProps {
    name: string;
    detail?: string;
    isHead?: boolean;
}

function CompanyLabel({ name, detail, isHead = false }: CompanyLabelProps) {
    return (
        <div className="space-y-1">
            <span className="block font-medium wrap-break-word">{name}</span>
            {(detail || isHead) && (
                <div className="flex flex-wrap items-center gap-2 text-xs font-normal text-muted-foreground">
                    {isHead && <Badge variant="secondary">Induk</Badge>}
                    {detail && <span>{detail}</span>}
                </div>
            )}
        </div>
    );
}

interface CompanyTableProps {
    context: ConsolidatedContext;
    report: ConsolidatedReport;
    meta: ReportMeta<MetricKey>;
    loading: boolean;
}

function CompanyTable({ context, report, meta, loading }: CompanyTableProps) {
    const currency = context.currency ?? context.head.currency;
    const companies = new Map(context.companies.map((company) => [company.id, company]));
    const rows = report.rows.flatMap(({ company_id, metrics, sla }): CompanyRow[] => {
        const company = companies.get(company_id);

        return company ? [{ company, metrics, sla }] : [];
    });

    const columns: DataTableColumn<CompanyRow>[] = [
        {
            key: 'company',
            header: 'Perusahaan',
            cell: ({ company }) => (
                <div className="min-w-48">
                    <CompanyLabel
                        name={company.name}
                        detail={`${company.code}, ${company.timezone}`}
                        isHead={company.id === context.head.id}
                    />
                </div>
            ),
        },
        ...METRIC_KEYS.map((key): DataTableColumn<CompanyRow> => ({
            key,
            header: meta.metrics[key].label,
            className: 'whitespace-nowrap text-right tabular-nums',
            headerClassName: 'text-right',
            cell: ({ metrics, sla }) => (
                <MetricValue metric={key} metrics={metrics} sla={sla} currency={currency} />
            ),
        })),
    ];

    // Seven columns need about 840px beside the sidebar (from 1200px): a phone gets one card per company, a tablet two.
    const cards = [
        ...rows.map(({ company, metrics, sla }) => ({
            key: String(company.id),
            label: (
                <CompanyLabel
                    name={company.name}
                    detail={`${company.code}, ${company.timezone}`}
                    isHead={company.id === context.head.id}
                />
            ),
            metrics,
            sla,
            total: false,
        })),
        {
            key: 'total',
            label: <CompanyLabel name="Total konsolidasi" />,
            metrics: report.total,
            sla: report.sla,
            total: true,
        },
    ];

    return (
        <>
            {loading ? (
                <LoadingState
                    variant="rows"
                    rows={3}
                    label="Memuat angka konsolidasi"
                    className="min-[1200px]:hidden"
                />
            ) : (
                <ul
                    aria-labelledby="consolidated-figures"
                    className="grid gap-3 min-[1200px]:hidden sm:grid-cols-2"
                >
                    {cards.map((card) => (
                        <li key={card.key} className={card.total ? 'sm:col-span-2' : undefined}>
                            <Card className={card.total ? 'bg-muted/50' : undefined}>
                                <CardContent className="space-y-3 pt-4">
                                    {card.label}
                                    <dl className="space-y-2 border-t border-border pt-3">
                                        {METRIC_KEYS.map((key) => (
                                            <div
                                                key={key}
                                                className="flex items-baseline justify-between gap-4"
                                            >
                                                <dt className="text-sm text-muted-foreground">
                                                    {meta.metrics[key].label}
                                                </dt>
                                                <dd
                                                    className={cn(
                                                        'text-right text-sm tabular-nums',
                                                        card.total
                                                            ? 'font-semibold'
                                                            : 'font-medium',
                                                    )}
                                                >
                                                    <MetricValue
                                                        metric={key}
                                                        metrics={card.metrics}
                                                        sla={card.sla}
                                                        currency={currency}
                                                    />
                                                </dd>
                                            </div>
                                        ))}
                                    </dl>
                                </CardContent>
                            </Card>
                        </li>
                    ))}
                </ul>
            )}
            <div className="hidden min-[1200px]:block">
                <DataTable
                    columns={columns}
                    data={rows}
                    rowKey={(row) => row.company.id}
                    loading={loading}
                    footer={
                        loading ? undefined : (
                            <TR>
                                <TD className="font-semibold">Total konsolidasi</TD>
                                {METRIC_KEYS.map((key) => (
                                    <TD
                                        key={key}
                                        className="text-right font-semibold whitespace-nowrap tabular-nums"
                                    >
                                        <MetricValue
                                            metric={key}
                                            metrics={report.total}
                                            sla={report.sla}
                                            currency={currency}
                                        />
                                    </TD>
                                ))}
                            </TR>
                        )
                    }
                />
            </div>
        </>
    );
}

export default function Consolidated({ context, data, refusal, filters, meta, can }: Props) {
    const { company, errors: pageErrors } = usePage<PageProps>().props as PageProps & {
        errors?: Record<string, string | undefined>;
    };
    const errors = pageErrors ?? {};
    const permitted = usePermission();
    const [range, setRange] = useState<DateRange>({
        from: filters.date_from || null,
        to: filters.date_to || null,
    });
    const { pending, callbacks } = useVisitPending();

    const submit = (event: FormEvent) => {
        event.preventDefault();
        router.get(
            route('admin.reports.consolidated'),
            { date_from: range.from ?? '', date_to: range.to ?? '' },
            { preserveState: true, ...callbacks },
        );
    };

    const subsidiaries = context.companies.length - 1;
    const subtitle =
        `Metrik bisnis gabungan ${context.head.name} dan ${subsidiaries} anak perusahaan.` +
        (context.currency ? ` Nilai dalam ${context.currency}.` : '');
    const periodFrom = formatDate(filters.date_from, company);
    const periodTo = formatDate(filters.date_to, company);
    const period =
        filters.date_from && filters.date_to
            ? `Periode: ${periodFrom} sampai ${periodTo}, kedua hari dihitung.`
            : filters.date_from
              ? `Periode: ${periodFrom} sampai hari ini, kedua hari dihitung.`
              : filters.date_to
                ? `Periode: semua data sampai ${periodTo}, hari itu dihitung.`
                : 'Periode: semua waktu. Pilih rentang tanggal untuk membatasi periode.';

    return (
        <AdminLayout title="Metrik Konsolidasi">
            <div className="space-y-6">
                <PageHeader
                    title="Metrik Konsolidasi"
                    subtitle={subtitle}
                    actions={
                        <>
                            <FormulaVersionLabel version={meta.formula_version} />
                            <ExportMenu
                                exportUrl={route('admin.reports.consolidated.export')}
                                params={filters}
                                formats={['csv']}
                                canExport={can.export && refusal === null}
                            />
                        </>
                    }
                />
                <Card>
                    <CardContent className="space-y-4 pt-6">
                        <form onSubmit={submit} className="grid gap-2 sm:flex sm:flex-wrap">
                            <div className="min-w-0 sm:w-96">
                                <DateRangePicker
                                    id="consolidated-range"
                                    label="Periode"
                                    value={range}
                                    onChange={setRange}
                                    error={errors.date_from ?? errors.date_to}
                                />
                            </div>
                            <div className="self-end">
                                <Button
                                    type="submit"
                                    className="h-11 w-full sm:w-auto"
                                    loading={pending}
                                >
                                    Tampilkan
                                </Button>
                            </div>
                        </form>
                        <div className="space-y-1 text-sm text-muted-foreground">
                            <p>{period}</p>
                            <p>
                                Laporan mencakup perusahaan induk dan anak perusahaannya yang aktif.
                                Periode tiap perusahaan dihitung per hari kalender di zona waktunya
                                sendiri.
                            </p>
                        </div>
                    </CardContent>
                </Card>
                {refusal ? (
                    <Alert variant="warning">
                        <AlertTriangle className="h-4 w-4" aria-hidden="true" />
                        <div className="space-y-2">
                            <p className="font-medium">
                                Perusahaan ini tidak bisa dikonsolidasikan
                            </p>
                            <p>{refusal.message}</p>
                            {permitted('report.business.view') && (
                                <p>
                                    Angka tiap perusahaan tetap bisa dibuka di{' '}
                                    <Link
                                        href={route('admin.reports.business')}
                                        className="rounded-sm font-medium underline underline-offset-2 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-hidden"
                                    >
                                        Metrik Bisnis
                                    </Link>
                                    .
                                </p>
                            )}
                        </div>
                    </Alert>
                ) : (
                    data && (
                        <>
                            <section aria-labelledby="consolidated-figures" className="space-y-3">
                                <h2
                                    id="consolidated-figures"
                                    className="text-lg font-semibold tracking-tight"
                                >
                                    Angka per perusahaan
                                </h2>
                                <CompanyTable
                                    context={context}
                                    report={data}
                                    meta={meta}
                                    loading={pending}
                                />
                                <p className="text-sm text-muted-foreground">
                                    Kepatuhan SLA konsolidasi dihitung gabungan: tiket tepat waktu
                                    dari semua perusahaan dibagi tiket yang selesai, bukan rata-rata
                                    persentase tiap perusahaan.
                                </p>
                            </section>
                            <section
                                aria-labelledby="consolidated-definitions"
                                className="space-y-3"
                            >
                                <h2
                                    id="consolidated-definitions"
                                    className="text-lg font-semibold tracking-tight"
                                >
                                    Cara menghitung setiap angka
                                </h2>
                                <Card>
                                    <CardContent className="pt-6">
                                        <dl className="divide-y divide-border">
                                            {METRIC_KEYS.map((key) => (
                                                <div
                                                    key={key}
                                                    className="grid gap-2 py-4 first:pt-0 last:pb-0 md:grid-cols-[12rem_minmax(0,1fr)] md:gap-6"
                                                >
                                                    <dt className="font-medium">
                                                        {meta.metrics[key].label}
                                                    </dt>
                                                    <dd className="min-w-0">
                                                        <MetricDefinitionNote
                                                            definition={meta.metrics[key]}
                                                            className="mt-0 border-t-0 pt-0"
                                                        />
                                                    </dd>
                                                </div>
                                            ))}
                                        </dl>
                                    </CardContent>
                                </Card>
                            </section>
                        </>
                    )
                )}
            </div>
        </AdminLayout>
    );
}
