import type { FormEvent } from 'react';
import { useState } from 'react';
import { router, usePage } from '@inertiajs/react';
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
import { formatDecimal, formatPercent } from '@/lib/format';
import type { ReportMeta, SlaChartData } from '@/types';
import { DrillLink, type DrillLinks } from './components/DrillLink';
import { ReportExportMenu } from './components/ReportExportMenu';
import { SlaCharts } from './components/SlaCharts';

type SlaMetricKey =
    | 'total_resolved'
    | 'sla_rate'
    | 'response_rate'
    | 'breach_count'
    | 'avg_resolution_minutes'
    | 'by_category';

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
}

interface Props extends Record<string, unknown> {
    data?: SlaData;
    charts: SlaChartData;
    filters: { date_from?: string; date_to?: string; category_id?: string };
    meta: ReportMeta<SlaMetricKey>;
    can: { export: boolean };
    drill: DrillLinks;
}

export default function Sla({ data, charts, filters, meta, can, drill }: Props) {
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
                    subtitle="Tingkat penyelesaian sesuai SLA, jumlah pelanggaran, dan rincian per kategori."
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
                    </div>
                )}
            </div>
        </AdminLayout>
    );
}
