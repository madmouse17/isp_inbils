import type { FormEvent } from 'react';
import { useState } from 'react';
import { router } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import {
    FormulaVersionLabel,
    LoadingState,
    MetricDefinitionNote,
    PageHeader,
} from '@/Components/composite';
import { Button, Card, CardContent, Input, Table, TBody, TD, TH, THead, TR } from '@/Components/ui';
import { useVisitPending } from '@/hooks/useVisitPending';
import type { ReportMeta } from '@/types';
import { DrillLink, type DrillLinks } from './components/DrillLink';
import { ReportExportMenu } from './components/ReportExportMenu';

type SlaMetricKey =
    'total_resolved' | 'sla_rate' | 'breach_count' | 'avg_resolution_minutes' | 'by_category';

interface SlaData extends Record<string, unknown> {
    total_resolved?: number;
    sla_compliant?: number;
    sla_rate?: number;
    breach_count?: number;
    avg_resolution_minutes?: number | null;
    avg_frt_minutes?: number | null;
    by_category?: { name: string; total: number; compliant: number; rate: number }[];
}

interface Props extends Record<string, unknown> {
    data?: SlaData;
    filters: { date_from?: string; date_to?: string; category_id?: string };
    meta: ReportMeta<SlaMetricKey>;
    can: { export: boolean };
    drill: DrillLinks;
}

export default function Sla({ data, filters, meta, can, drill }: Props) {
    const [dateFrom, setDateFrom] = useState(filters.date_from ?? '');
    const [dateTo, setDateTo] = useState(filters.date_to ?? '');
    const { pending, callbacks } = useVisitPending();

    const submit = (e: FormEvent) => {
        e.preventDefault();
        router.get(
            route('admin.reports.sla'),
            { date_from: dateFrom, date_to: dateTo },
            { preserveState: true, ...callbacks },
        );
    };

    return (
        <AdminLayout title="SLA Compliance">
            <div className="space-y-6">
                <PageHeader
                    title="SLA Compliance"
                    subtitle="Resolution rate, breach count, and per-category breakdown."
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
                            <Input
                                label="From"
                                type="date"
                                value={dateFrom}
                                onChange={(e) => setDateFrom(e.target.value)}
                            />
                            <Input
                                label="To"
                                type="date"
                                value={dateTo}
                                onChange={(e) => setDateTo(e.target.value)}
                            />
                            <div className="self-end">
                                <Button type="submit" loading={pending}>
                                    Run
                                </Button>
                            </div>
                        </form>
                    </CardContent>
                </Card>
                {pending && <LoadingState variant="section" />}
                {!pending && data && (
                    <div className="space-y-4">
                        <div className="grid grid-cols-1 items-start gap-4 md:grid-cols-2 xl:grid-cols-4">
                            <Card>
                                <CardContent>
                                    <p className="text-sm text-surface-500">
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
                                    <p className="text-sm text-surface-500">
                                        {meta.metrics.sla_rate.label}
                                    </p>
                                    <p className="text-2xl font-bold">{data.sla_rate ?? 0}%</p>
                                    <DrillLink
                                        href={drill.sla_rate}
                                        label={meta.metrics.sla_rate.label}
                                    />
                                    <MetricDefinitionNote definition={meta.metrics.sla_rate} />
                                </CardContent>
                            </Card>
                            <Card>
                                <CardContent>
                                    <p className="text-sm text-surface-500">
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
                                    <p className="text-sm text-surface-500">
                                        {meta.metrics.avg_resolution_minutes.label}
                                    </p>
                                    <p className="text-2xl font-bold">
                                        {data.avg_resolution_minutes ?? '-'} min
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
                                            <TH>Category</TH>
                                            <TH>Total</TH>
                                            <TH>Compliant</TH>
                                            <TH>Rate</TH>
                                        </TR>
                                    </THead>
                                    <TBody>
                                        {(data.by_category ?? []).length === 0 ? (
                                            <TR>
                                                <TD
                                                    colSpan={4}
                                                    className="py-10 text-center text-muted-foreground"
                                                >
                                                    No data found.
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
                                                            label={`${c.name} resolved tickets`}
                                                            group={c.name}
                                                        />
                                                    </TD>
                                                    <TD>{c.compliant}</TD>
                                                    <TD>{c.rate}%</TD>
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
