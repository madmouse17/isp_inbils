import type { FormEvent, ReactNode } from 'react';
import { useState } from 'react';
import { Link, router, usePage } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import {
    FormulaVersionLabel,
    LoadingState,
    MetricDefinitionNote,
    PageHeader,
} from '@/Components/composite';
import {
    Button,
    Card,
    CardContent,
    DateRangePicker,
    Table,
    TBody,
    TD,
    TH,
    THead,
    TR,
    type DateRange,
} from '@/Components/ui';
import { useVisitPending } from '@/hooks/useVisitPending';
import { formatDecimal, formatNumber, formatPercent } from '@/lib/format';
import { labelOf, PRIORITY } from '@/lib/statuses';
import type {
    MetricDefinitionData,
    ReportMeta,
    SlaChartData,
    SlaSplitRow,
    SlaViolator,
} from '@/types';
import { DrillLink, type DrillLinks } from './components/DrillLink';
import { formatMinutes } from './components/formatMinutes';
import { ReportExportMenu } from './components/ReportExportMenu';
import { SlaCharts } from './components/SlaCharts';

type SlaMetricKey =
    | 'total_resolved'
    | 'sla_rate'
    | 'response_rate'
    | 'breach_count'
    | 'avg_resolution_minutes'
    | 'by_category'
    | 'by_technician'
    | 'by_priority'
    | 'by_source'
    | 'csat_average'
    | 'csat_count';

interface SlaData extends Record<string, unknown> {
    total_resolved?: number;
    sla_compliant?: number;
    sla_rate?: number;
    breach_count?: number;
    avg_resolution_minutes?: number | null;
    avg_frt_minutes?: number | null;
    by_category?: { name: string; total: number; compliant: number; rate: number }[];
    response_on_time?: number;
    response_breached?: number;
    response_without_target?: number;
    response_rate?: number | null;
    by_technician?: SlaSplitRow[];
    by_priority?: SlaSplitRow[];
    by_source?: SlaSplitRow[];
    csat_average?: number | null;
    csat_count?: number;
}

interface Props extends Record<string, unknown> {
    data?: SlaData;
    charts: SlaChartData;
    /** The ten tickets that missed their deadline by the most; null for a viewer who may not list tickets. */
    violators: SlaViolator[] | null;
    filters: { date_from?: string; date_to?: string; category_id?: string };
    meta: ReportMeta<SlaMetricKey>;
    can: { export: boolean };
    drill: DrillLinks;
}

interface SplitCardProps {
    definition: MetricDefinitionData;
    /** Heading of the first column: what the rows are grouped by. */
    groupedBy: string;
    rows: SlaSplitRow[];
    nameOf: (name: string | null) => string;
}

/** The tickets resolved in the period for each handler, priority or target source: how many, on time, late and how fast the first answer was. */
function SplitCard({ definition, groupedBy, rows, nameOf }: SplitCardProps) {
    return (
        <Card>
            <CardContent className="space-y-4 pt-6">
                <div className="min-w-0">
                    <p className="font-medium">{definition.label}</p>
                    <MetricDefinitionNote
                        definition={definition}
                        className="mt-2 border-t-0 pt-0"
                    />
                </div>
                <Table>
                    <THead>
                        <TR>
                            <TH>{groupedBy}</TH>
                            <TH className="text-right">Tiket selesai</TH>
                            <TH className="text-right">Sesuai SLA</TH>
                            <TH className="text-right">Melanggar</TH>
                            <TH className="text-right">Persentase</TH>
                            <TH className="text-right">Respons pertama</TH>
                        </TR>
                    </THead>
                    <TBody>
                        {rows.length === 0 ? (
                            <TR>
                                <TD colSpan={6} className="py-10 text-center text-muted-foreground">
                                    Tidak ada data.
                                </TD>
                            </TR>
                        ) : (
                            rows.map((row, index) => (
                                <TR key={`${row.name ?? '-'}-${index}`}>
                                    <TD>{nameOf(row.name)}</TD>
                                    <TD className="text-right tabular-nums">
                                        {formatNumber(row.total)}
                                    </TD>
                                    <TD className="text-right tabular-nums">
                                        {formatNumber(row.compliant)}
                                    </TD>
                                    <TD className="text-right tabular-nums">
                                        {formatNumber(row.breached)}
                                    </TD>
                                    <TD className="text-right tabular-nums">
                                        {formatPercent(row.rate)}
                                    </TD>
                                    <TD className="text-right whitespace-nowrap tabular-nums">
                                        {formatMinutes(row.avg_frt_minutes)}
                                    </TD>
                                </TR>
                            ))
                        )}
                    </TBody>
                </Table>
            </CardContent>
        </Card>
    );
}

function Cell({ children }: { children: ReactNode }) {
    return <TD className="max-w-64 truncate">{children}</TD>;
}

export default function Sla({ data, charts, violators, filters, meta, can, drill }: Props) {
    const errors = (usePage().props.errors ?? {}) as Record<string, string | undefined>;
    const [range, setRange] = useState<DateRange>({
        from: filters.date_from ?? null,
        to: filters.date_to ?? null,
    });
    const { pending, callbacks } = useVisitPending();

    const submit = (e: FormEvent) => {
        e.preventDefault();
        router.get(
            route('admin.reports.sla'),
            { date_from: range.from ?? '', date_to: range.to ?? '' },
            { preserveState: true, ...callbacks },
        );
    };

    return (
        <AdminLayout title="Kepatuhan SLA">
            <div className="space-y-6">
                <PageHeader
                    title="Kepatuhan SLA"
                    subtitle="Tingkat penyelesaian sesuai SLA, jumlah pelanggaran, rincian per kategori, teknisi, prioritas, dan sumber target, serta kepuasan pelanggan."
                    actions={
                        <>
                            <FormulaVersionLabel version={meta.formula_version} />
                            <ReportExportMenu
                                url={route('admin.reports.sla.export')}
                                params={filters}
                                canExport={can.export}
                            />
                        </>
                    }
                />
                <Card>
                    <CardContent className="space-y-4 pt-6">
                        <form onSubmit={submit} className="grid gap-2 sm:flex">
                            <DateRangePicker
                                label="Periode"
                                value={range}
                                onChange={setRange}
                                error={errors.date_from ?? errors.date_to}
                                className="sm:w-80"
                            />
                            <div className="self-end">
                                <Button type="submit" loading={pending}>
                                    Tampilkan
                                </Button>
                            </div>
                        </form>
                    </CardContent>
                </Card>
                {pending && <LoadingState variant="section" />}
                {!pending && data && (
                    <div className="space-y-4">
                        <div className="grid grid-cols-1 items-start gap-4 md:grid-cols-2 xl:grid-cols-3">
                            <Card>
                                <CardContent>
                                    <p className="text-sm text-muted-foreground">
                                        {meta.metrics.total_resolved.label}
                                    </p>
                                    <p className="text-2xl font-bold">{data.total_resolved ?? 0}</p>
                                    <DrillLink
                                        href={drill.total_resolved}
                                        label={meta.metrics.total_resolved.label}
                                    />
                                    <MetricDefinitionNote
                                        definition={meta.metrics.total_resolved}
                                    />
                                </CardContent>
                            </Card>
                            <Card>
                                <CardContent>
                                    <p className="text-sm text-muted-foreground">
                                        {meta.metrics.sla_rate.label}
                                    </p>
                                    <p className="text-2xl font-bold">
                                        {formatPercent(data.sla_rate)}
                                    </p>
                                    <DrillLink
                                        href={drill.sla_rate}
                                        label={meta.metrics.sla_rate.label}
                                    />
                                    <MetricDefinitionNote definition={meta.metrics.sla_rate} />
                                </CardContent>
                            </Card>
                            <Card>
                                <CardContent>
                                    <p className="text-sm text-muted-foreground">
                                        {meta.metrics.response_rate.label}
                                    </p>
                                    <p className="text-2xl font-bold">
                                        {formatPercent(data.response_rate)}
                                    </p>
                                    <p className="text-sm text-muted-foreground">
                                        {data.response_on_time ?? 0} tepat waktu,{' '}
                                        {data.response_breached ?? 0} terlambat,{' '}
                                        {data.response_without_target ?? 0} tanpa target respons
                                    </p>
                                    <DrillLink
                                        href={drill.response_rate}
                                        label={meta.metrics.response_rate.label}
                                    />
                                    <MetricDefinitionNote definition={meta.metrics.response_rate} />
                                </CardContent>
                            </Card>
                            <Card>
                                <CardContent>
                                    <p className="text-sm text-muted-foreground">
                                        {meta.metrics.breach_count.label}
                                    </p>
                                    <p className="text-2xl font-bold">{data.breach_count ?? 0}</p>
                                    <DrillLink
                                        href={drill.breach_count}
                                        label={meta.metrics.breach_count.label}
                                    />
                                    <MetricDefinitionNote definition={meta.metrics.breach_count} />
                                </CardContent>
                            </Card>
                            <Card>
                                <CardContent>
                                    <p className="text-sm text-muted-foreground">
                                        {meta.metrics.avg_resolution_minutes.label}
                                    </p>
                                    <p className="text-2xl font-bold">
                                        {formatDecimal(data.avg_resolution_minutes)} menit
                                    </p>
                                    <DrillLink
                                        href={drill.avg_resolution_minutes}
                                        label={meta.metrics.avg_resolution_minutes.label}
                                    />
                                    <MetricDefinitionNote
                                        definition={meta.metrics.avg_resolution_minutes}
                                    />
                                </CardContent>
                            </Card>
                            <Card>
                                <CardContent>
                                    <p className="text-sm text-muted-foreground">
                                        {meta.metrics.csat_average.label}
                                    </p>
                                    <p className="text-2xl font-bold">
                                        {data.csat_average == null ? (
                                            '-'
                                        ) : (
                                            <>
                                                {formatDecimal(data.csat_average)}
                                                <span className="ml-1 text-sm font-normal text-muted-foreground">
                                                    dari 5
                                                </span>
                                            </>
                                        )}
                                    </p>
                                    <p className="text-sm text-muted-foreground">
                                        {(data.csat_count ?? 0) === 0
                                            ? 'Belum ada penilaian pada periode ini.'
                                            : `${formatNumber(data.csat_count ?? 0)} penilaian`}
                                    </p>
                                    <MetricDefinitionNote definition={meta.metrics.csat_average} />
                                </CardContent>
                            </Card>
                        </div>
                        <SlaCharts charts={charts} byCategory={data.by_category ?? []} />
                        <Card>
                            <CardContent className="space-y-4 pt-6">
                                <div className="min-w-0">
                                    <p className="font-medium">{meta.metrics.by_category.label}</p>
                                    <MetricDefinitionNote
                                        definition={meta.metrics.by_category}
                                        className="mt-2 border-t-0 pt-0"
                                    />
                                </div>
                                <Table>
                                    <THead>
                                        <TR>
                                            <TH>Kategori</TH>
                                            <TH>Total</TH>
                                            <TH>Sesuai SLA</TH>
                                            <TH>Persentase</TH>
                                        </TR>
                                    </THead>
                                    <TBody>
                                        {(data.by_category ?? []).length === 0 ? (
                                            <TR>
                                                <TD
                                                    colSpan={4}
                                                    className="py-10 text-center text-muted-foreground"
                                                >
                                                    Tidak ada data.
                                                </TD>
                                            </TR>
                                        ) : (
                                            (data.by_category ?? []).map((c, i) => (
                                                <TR key={i}>
                                                    <TD>{c.name}</TD>
                                                    <TD>
                                                        {c.total}
                                                        <DrillLink
                                                            href={drill.by_category}
                                                            label={`tiket selesai ${c.name}`}
                                                            group={c.name}
                                                        />
                                                    </TD>
                                                    <TD>{c.compliant}</TD>
                                                    <TD>{formatPercent(c.rate)}</TD>
                                                </TR>
                                            ))
                                        )}
                                    </TBody>
                                </Table>
                            </CardContent>
                        </Card>
                        <SplitCard
                            definition={meta.metrics.by_technician}
                            groupedBy="Teknisi"
                            rows={data.by_technician ?? []}
                            nameOf={(name) => name ?? 'Belum ditugaskan'}
                        />
                        <SplitCard
                            definition={meta.metrics.by_priority}
                            groupedBy="Prioritas"
                            rows={data.by_priority ?? []}
                            nameOf={(name) => labelOf(PRIORITY, name)}
                        />
                        <SplitCard
                            definition={meta.metrics.by_source}
                            groupedBy="Sumber target"
                            rows={data.by_source ?? []}
                            nameOf={(name) =>
                                name === null ? 'Target kategori tiket' : `Tingkat SLA ${name}`
                            }
                        />
                        {violators !== null && (
                            <Card>
                                <CardContent className="space-y-4 pt-6">
                                    <div className="min-w-0">
                                        <p className="font-medium">Pelanggaran terlama</p>
                                        <p className="text-sm text-muted-foreground">
                                            Sepuluh tiket selesai yang paling lama melewati batas
                                            SLA pada periode ini.
                                        </p>
                                        <DrillLink
                                            href={drill.breach_count}
                                            label={meta.metrics.breach_count.label}
                                            text="Lihat semua pelanggaran"
                                        />
                                    </div>
                                    <Table>
                                        <THead>
                                            <TR>
                                                <TH>Tiket</TH>
                                                <TH>Judul</TH>
                                                <TH>Kategori</TH>
                                                <TH>Penangan</TH>
                                                <TH>Prioritas</TH>
                                                <TH className="text-right">Terlambat</TH>
                                            </TR>
                                        </THead>
                                        <TBody>
                                            {violators.length === 0 ? (
                                                <TR>
                                                    <TD
                                                        colSpan={6}
                                                        className="py-10 text-center text-muted-foreground"
                                                    >
                                                        Tidak ada tiket yang melanggar SLA pada
                                                        periode ini.
                                                    </TD>
                                                </TR>
                                            ) : (
                                                violators.map((ticket) => (
                                                    <TR key={ticket.id}>
                                                        <TD className="whitespace-nowrap">
                                                            <Link
                                                                href={ticket.url}
                                                                className="rounded-sm font-medium text-primary-text underline underline-offset-2 focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background focus-visible:outline-hidden"
                                                            >
                                                                {ticket.code}
                                                            </Link>
                                                        </TD>
                                                        <Cell>{ticket.title}</Cell>
                                                        <Cell>{ticket.category ?? '-'}</Cell>
                                                        <Cell>{ticket.assignee ?? '-'}</Cell>
                                                        <TD>
                                                            {labelOf(PRIORITY, ticket.priority)}
                                                        </TD>
                                                        <TD className="text-right whitespace-nowrap tabular-nums">
                                                            {formatMinutes(ticket.late_minutes)}
                                                        </TD>
                                                    </TR>
                                                ))
                                            )}
                                        </TBody>
                                    </Table>
                                </CardContent>
                            </Card>
                        )}
                    </div>
                )}
            </div>
        </AdminLayout>
    );
}
