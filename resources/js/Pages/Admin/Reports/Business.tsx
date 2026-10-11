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
import { Button, Card, CardContent, CardHeader, CardTitle, Input } from '@/Components/ui';
import { useVisitPending } from '@/hooks/useVisitPending';
import type { ReportMeta } from '@/types';
import { DrillLink, type DrillLinks } from './components/DrillLink';
import { ReportExportMenu } from './components/ReportExportMenu';

type BusinessMetricKey =
    | 'mrr'
    | 'revenue_paid'
    | 'outstanding'
    | 'active_subscriptions'
    | 'suspended_subscriptions'
    | 'sla_compliance_pct';

interface BusinessData extends Record<string, unknown> {
    mrr?: string;
    active_subscriptions?: number;
    suspended_subscriptions?: number;
    terminated_subscriptions?: number;
    new_subscriptions?: number;
    churn?: number;
    revenue_paid?: string;
    recurring_revenue?: string;
    one_time_revenue?: string;
    outstanding?: string;
    sla_compliance_pct?: number;
    installation_count?: number;
    asset_distribution?: Record<string, number>;
}

interface Props extends Record<string, unknown> {
    data?: BusinessData;
    filters: { date_from?: string; date_to?: string };
    meta: ReportMeta<BusinessMetricKey>;
    can: { export: boolean };
    drill: DrillLinks;
}

export default function Business({ data, filters, meta, can, drill }: Props) {
    const [dateFrom, setDateFrom] = useState(filters.date_from ?? '');
    const [dateTo, setDateTo] = useState(filters.date_to ?? '');
    const { pending, callbacks } = useVisitPending();

    const submit = (e: FormEvent) => {
        e.preventDefault();
        router.get(
            route('admin.reports.business'),
            { date_from: dateFrom, date_to: dateTo },
            { preserveState: true, ...callbacks },
        );
    };

    return (
        <AdminLayout title="Business Metrics">
            <div className="space-y-6">
                <PageHeader
                    title="Business Metrics"
                    subtitle="MRR, revenue, churn, outstanding, and asset utilization."
                    actions={
                        <>
                            <FormulaVersionLabel version={meta.formula_version} />
                            <ReportExportMenu
                                url={route('admin.reports.business.export')}
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
                    <div className="grid grid-cols-1 items-start gap-4 md:grid-cols-2 xl:grid-cols-3">
                        <Card>
                            <CardHeader>
                                <CardTitle>{meta.metrics.mrr.label}</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <p className="text-2xl font-bold">{data.mrr ?? '0'}</p>
                                <DrillLink href={drill.mrr} label={meta.metrics.mrr.label} />
                                <MetricDefinitionNote definition={meta.metrics.mrr} />
                            </CardContent>
                        </Card>
                        <Card>
                            <CardHeader>
                                <CardTitle>{meta.metrics.revenue_paid.label}</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <p className="text-2xl font-bold">{data.revenue_paid ?? '0'}</p>
                                <DrillLink
                                    href={drill.revenue_paid}
                                    label={meta.metrics.revenue_paid.label}
                                />
                                <MetricDefinitionNote definition={meta.metrics.revenue_paid} />
                            </CardContent>
                        </Card>
                        <Card>
                            <CardHeader>
                                <CardTitle>{meta.metrics.outstanding.label}</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <p className="text-2xl font-bold">{data.outstanding ?? '0'}</p>
                                <DrillLink
                                    href={drill.outstanding}
                                    label={meta.metrics.outstanding.label}
                                />
                                <MetricDefinitionNote definition={meta.metrics.outstanding} />
                            </CardContent>
                        </Card>
                        <Card>
                            <CardHeader>
                                <CardTitle>{meta.metrics.active_subscriptions.label}</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <p className="text-2xl font-bold">
                                    {data.active_subscriptions ?? 0}
                                </p>
                                <DrillLink
                                    href={drill.active_subscriptions}
                                    label={meta.metrics.active_subscriptions.label}
                                />
                                <MetricDefinitionNote
                                    definition={meta.metrics.active_subscriptions}
                                />
                            </CardContent>
                        </Card>
                        <Card>
                            <CardHeader>
                                <CardTitle>{meta.metrics.suspended_subscriptions.label}</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <p className="text-2xl font-bold">
                                    {data.suspended_subscriptions ?? 0}
                                </p>
                                <DrillLink
                                    href={drill.suspended_subscriptions}
                                    label={meta.metrics.suspended_subscriptions.label}
                                />
                                <MetricDefinitionNote
                                    definition={meta.metrics.suspended_subscriptions}
                                />
                            </CardContent>
                        </Card>
                        <Card>
                            <CardHeader>
                                <CardTitle>{meta.metrics.sla_compliance_pct.label}</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <p className="text-2xl font-bold">
                                    {data.sla_compliance_pct ?? 0}%
                                </p>
                                <DrillLink
                                    href={drill.sla_compliance_pct}
                                    label={meta.metrics.sla_compliance_pct.label}
                                />
                                <MetricDefinitionNote
                                    definition={meta.metrics.sla_compliance_pct}
                                />
                            </CardContent>
                        </Card>
                    </div>
                )}
            </div>
        </AdminLayout>
    );
}
