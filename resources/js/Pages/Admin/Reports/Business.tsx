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
    CardHeader,
    CardTitle,
    DateRangePicker,
    SearchSelect,
    type DateRange,
} from '@/Components/ui';
import { useVisitPending } from '@/hooks/useVisitPending';
import { formatNumber, formatPercent } from '@/lib/format';
import type { BusinessChartData, ReportMeta } from '@/types';
import { DrillLink, type DrillLinks } from './components/DrillLink';
import { MoneyFigure } from './components/MoneyFigure';
import { BusinessCharts } from './components/BusinessCharts';
import { ReportExportMenu } from './components/ReportExportMenu';

type BusinessMetricKey =
    | 'mrr'
    | 'revenue_paid'
    | 'outstanding'
    | 'active_subscriptions'
    | 'suspended_subscriptions'
    | 'sla_compliance_pct';

/** The six figure cards, in the order shown; `kind` picks how the value is written. */
const FIGURES: { key: BusinessMetricKey; kind: 'money' | 'count' | 'percent' }[] = [
    { key: 'mrr', kind: 'money' },
    { key: 'revenue_paid', kind: 'money' },
    { key: 'outstanding', kind: 'money' },
    { key: 'active_subscriptions', kind: 'count' },
    { key: 'suspended_subscriptions', kind: 'count' },
    { key: 'sla_compliance_pct', kind: 'percent' },
];

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
    charts: BusinessChartData;
    packageCategories: { id: string; code: string; name: string; is_active: boolean }[];
    filters: { date_from?: string; date_to?: string; service_package_category_id?: string | null };
    meta: ReportMeta<BusinessMetricKey>;
    can: { export: boolean };
    drill: DrillLinks;
}

export default function Business({
    data,
    charts,
    packageCategories,
    filters,
    meta,
    can,
    drill,
}: Props) {
    const errors = (usePage().props.errors ?? {}) as Record<string, string | undefined>;
    const [range, setRange] = useState<DateRange>({
        from: filters.date_from ?? null,
        to: filters.date_to ?? null,
    });
    const [categoryId, setCategoryId] = useState(filters.service_package_category_id ?? '');
    const { pending, callbacks } = useVisitPending();

    const submit = (e: FormEvent) => {
        e.preventDefault();
        router.get(
            route('admin.reports.business'),
            {
                date_from: range.from ?? '',
                date_to: range.to ?? '',
                service_package_category_id: categoryId,
            },
            { preserveState: true, ...callbacks },
        );
    };

    const figure = (key: BusinessMetricKey, kind: 'count' | 'percent'): string => {
        if (!data) return '';
        if (kind === 'count') return formatNumber(data[key] ?? 0);

        return formatPercent(data[key]);
    };

    return (
        <AdminLayout title="Metrik Bisnis">
            <div className="space-y-6">
                <PageHeader
                    title="Metrik Bisnis"
                    subtitle="MRR, pendapatan, piutang, dan jumlah langganan perusahaan ini."
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
                            <DateRangePicker
                                label="Periode"
                                value={range}
                                onChange={setRange}
                                error={errors.date_from ?? errors.date_to}
                                className="sm:w-80"
                            />
                            <div className="sm:w-64">
                                <SearchSelect
                                    label="Kategori paket"
                                    value={categoryId}
                                    onChange={setCategoryId}
                                    placeholder="Semua kategori"
                                    error={errors.service_package_category_id}
                                    hint="Aset, SLA, dan pelanggan baru tidak terpengaruh."
                                    options={packageCategories.map((category) => ({
                                        value: String(category.id),
                                        label: category.is_active
                                            ? category.name
                                            : `${category.name} (nonaktif)`,
                                    }))}
                                />
                            </div>
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
                    <div className="grid grid-cols-1 items-start gap-4 md:grid-cols-2 xl:grid-cols-3">
                        {FIGURES.map(({ key, kind }) => (
                            <Card key={key} className="min-w-0">
                                <CardHeader>
                                    <CardTitle>{meta.metrics[key].label}</CardTitle>
                                </CardHeader>
                                <CardContent>
                                    <p className="text-2xl font-bold wrap-break-word tabular-nums">
                                        {kind === 'money' ? (
                                            <MoneyFigure value={data[key] ?? 0} />
                                        ) : (
                                            figure(key, kind)
                                        )}
                                    </p>
                                    <DrillLink href={drill[key]} label={meta.metrics[key].label} />
                                    <MetricDefinitionNote definition={meta.metrics[key]} />
                                </CardContent>
                            </Card>
                        ))}
                    </div>
                )}
                {!pending && data && <BusinessCharts charts={charts} />}
            </div>
        </AdminLayout>
    );
}
