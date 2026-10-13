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
    NativeSelect,
    Table,
    TBody,
    TD,
    TH,
    THead,
    TR,
    type DateRange,
} from '@/Components/ui';
import { useVisitPending } from '@/hooks/useVisitPending';
import type { ReportMeta } from '@/types';
import { AssetCharts } from './components/AssetCharts';
import { codeLabel } from './components/chartData';
import { DrillLink, type DrillLinks } from './components/DrillLink';
import { ReportExportMenu } from './components/ReportExportMenu';

type AssetMetricKey =
    'installation_count' | 'customer_linked_count' | 'retired_count' | 'status_distribution';

interface AssetData extends Record<string, unknown> {
    status_distribution?: Record<string, number>;
    by_location?: Record<string, number>;
    installation_count?: number;
    customer_linked_count?: number;
    retired_count?: number;
}

interface Props extends Record<string, unknown> {
    data?: AssetData;
    filters: { asset_type?: string; location_id?: string; date_from?: string; date_to?: string };
    meta: ReportMeta<AssetMetricKey>;
    can: { export: boolean };
    drill: DrillLinks;
}

export default function Asset({ data, filters, meta, can, drill }: Props) {
    const errors = (usePage().props.errors ?? {}) as Record<string, string | undefined>;
    const [assetType, setAssetType] = useState(filters.asset_type ?? '');
    const [range, setRange] = useState<DateRange>({
        from: filters.date_from ?? null,
        to: filters.date_to ?? null,
    });
    const { pending, callbacks } = useVisitPending();

    const submit = (e: FormEvent) => {
        e.preventDefault();
        router.get(
            route('admin.reports.asset'),
            { asset_type: assetType, date_from: range.from ?? '', date_to: range.to ?? '' },
            { preserveState: true, ...callbacks },
        );
    };

    return (
        <AdminLayout title="Asset Utilization">
            <div className="space-y-6">
                <PageHeader
                    title="Asset Utilization"
                    subtitle="Status distribution, per-location, and installation history."
                    actions={
                        <>
                            <FormulaVersionLabel version={meta.formula_version} />
                            <ReportExportMenu
                                url={route('admin.reports.asset.export')}
                                params={filters}
                                canExport={can.export}
                            />
                        </>
                    }
                />
                <Card>
                    <CardContent className="space-y-4 pt-6">
                        <form onSubmit={submit} className="flex flex-wrap gap-2">
                            <NativeSelect
                                label="Asset Type"
                                value={assetType}
                                onChange={(e) => setAssetType(e.target.value)}
                            >
                                <option value="">All</option>
                                <option value="router">Router</option>
                                <option value="switch">Switch</option>
                                <option value="olt">OLT</option>
                                <option value="onu_ont">ONU/ONT</option>
                                <option value="radio">Radio</option>
                                <option value="antenna">Antenna</option>
                            </NativeSelect>
                            <DateRangePicker
                                label="Periode"
                                value={range}
                                onChange={setRange}
                                error={errors.date_from ?? errors.date_to}
                                className="sm:w-80"
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
                        <div className="grid grid-cols-1 items-start gap-4 md:grid-cols-2 xl:grid-cols-3">
                            <Card>
                                <CardContent>
                                    <p className="text-sm text-surface-500">
                                        {meta.metrics.installation_count.label}
                                    </p>
                                    <p className="text-2xl font-bold">
                                        {data.installation_count ?? 0}
                                    </p>
                                    <DrillLink
                                        href={drill.installation_count}
                                        label={meta.metrics.installation_count.label}
                                    />
                                    <MetricDefinitionNote
                                        definition={meta.metrics.installation_count}
                                    />
                                </CardContent>
                            </Card>
                            <Card>
                                <CardContent>
                                    <p className="text-sm text-surface-500">
                                        {meta.metrics.customer_linked_count.label}
                                    </p>
                                    <p className="text-2xl font-bold">
                                        {data.customer_linked_count ?? 0}
                                    </p>
                                    <DrillLink
                                        href={drill.customer_linked_count}
                                        label={meta.metrics.customer_linked_count.label}
                                    />
                                    <MetricDefinitionNote
                                        definition={meta.metrics.customer_linked_count}
                                    />
                                </CardContent>
                            </Card>
                            <Card>
                                <CardContent>
                                    <p className="text-sm text-surface-500">
                                        {meta.metrics.retired_count.label}
                                    </p>
                                    <p className="text-2xl font-bold">{data.retired_count ?? 0}</p>
                                    <DrillLink
                                        href={drill.retired_count}
                                        label={meta.metrics.retired_count.label}
                                    />
                                    <MetricDefinitionNote definition={meta.metrics.retired_count} />
                                </CardContent>
                            </Card>
                        </div>
                        <AssetCharts
                            statusDistribution={data.status_distribution}
                            byLocation={data.by_location}
                        />
                        <Card>
                            <CardContent>
                                <p className="mb-2 font-medium">
                                    {meta.metrics.status_distribution.label}
                                </p>
                                <MetricDefinitionNote
                                    definition={meta.metrics.status_distribution}
                                    className="mt-0 mb-4 border-t-0 pt-0"
                                />
                                <Table>
                                    <THead>
                                        <TR>
                                            <TH>Status</TH>
                                            <TH>Count</TH>
                                        </TR>
                                    </THead>
                                    <TBody>
                                        {Object.entries(data.status_distribution ?? {}).length ===
                                        0 ? (
                                            <TR>
                                                <TD
                                                    colSpan={2}
                                                    className="py-10 text-center text-muted-foreground"
                                                >
                                                    No data found.
                                                </TD>
                                            </TR>
                                        ) : (
                                            Object.entries(data.status_distribution ?? {}).map(
                                                ([s, c]) => (
                                                    <TR key={s}>
                                                        <TD>{codeLabel(s)}</TD>
                                                        <TD>
                                                            {c}
                                                            <DrillLink
                                                                href={drill.status_distribution}
                                                                label={`${s} assets`}
                                                                group={s}
                                                            />
                                                        </TD>
                                                    </TR>
                                                ),
                                            )
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
