import type { FormEvent } from 'react';
import { useEffect } from 'react';
import { useForm } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import { PageHeader } from '@/Components/composite';
import { RemoteSearchSelect, type RemoteOption } from '@/Components/composite/RemoteSearchSelect';
import { Button, Card, CardContent, CardHeader, CardTitle, DateRangePicker } from '@/Components/ui';
import type { ReportMeta, TechnicianChartData } from '@/types';
import { DrillLink, type DrillLinks } from './components/DrillLink';
import { ReportExportMenu } from './components/ReportExportMenu';
import { TechnicianCharts } from './components/TechnicianCharts';

type TechnicianMetricKey =
    | 'spk_completed'
    | 'tickets_resolved'
    | 'sla_compliance_pct'
    | 'avg_score'
    | 'avg_frt_minutes'
    | 'active_workload';

interface TechData extends Record<string, unknown> {
    technician_id?: number;
    spk_completed?: number;
    tickets_resolved?: number;
    avg_spk_minutes?: number | null;
    avg_ticket_minutes?: number | null;
    sla_compliance_pct?: number;
    avg_frt_minutes?: number | null;
    avg_score?: number | null;
    avg_customer_rating?: number | null;
    active_workload?: number;
    spk_by_type?: Record<string, number>;
    tickets_by_category?: Record<string, number>;
}

interface Props extends Record<string, unknown> {
    data?: TechData | null;
    charts: TechnicianChartData | null;
    technicianOptions?: RemoteOption[];
    selectedTechnician: RemoteOption | null;
    hasTechnicians: boolean;
    filters: { technician_id?: string; date_from?: string; date_to?: string };
    meta: ReportMeta<TechnicianMetricKey>;
    can: { export: boolean };
    drill: DrillLinks;
}

export default function Technician({
    data,
    charts,
    technicianOptions,
    selectedTechnician,
    hasTechnicians,
    filters,
    meta,
    can,
    drill,
}: Props) {
    const isSelf =
        typeof data?.technician_id === 'number' &&
        Number.isFinite(data.technician_id) &&
        filters.technician_id === undefined;
    const serverTarget = filters.technician_id ?? '';
    const {
        data: formData,
        setData,
        transform,
        get,
        processing,
        errors,
    } = useForm({
        technician_id: serverTarget,
        date_from: filters.date_from ?? '',
        date_to: filters.date_to ?? '',
    });

    useEffect(() => {
        setData({
            technician_id: serverTarget,
            date_from: filters.date_from ?? '',
            date_to: filters.date_to ?? '',
        });
    }, [filters.date_from, filters.date_to, serverTarget, setData]);

    const showMetrics = Boolean(
        data &&
        (isSelf ||
            (serverTarget !== '' &&
                String(data.technician_id) === serverTarget &&
                formData.technician_id === serverTarget)),
    );

    const submit = (e: FormEvent) => {
        e.preventDefault();
        if (!isSelf && formData.technician_id === '') return;

        transform((values) =>
            isSelf
                ? { date_from: values.date_from, date_to: values.date_to }
                : {
                      technician_id: values.technician_id,
                      date_from: values.date_from,
                      date_to: values.date_to,
                  },
        );
        get(route('admin.reports.technician'), { preserveState: true });
    };

    const noTechnicians = !hasTechnicians && selectedTechnician === null;

    return (
        <AdminLayout title="Kinerja Teknisi">
            <div className="space-y-6">
                <PageHeader
                    title="Kinerja Teknisi"
                    subtitle="SPK selesai, tiket selesai, kepatuhan SLA, nilai, dan waktu respons pertama per teknisi."
                    actions={
                        showMetrics && data ? (
                            <ReportExportMenu
                                url={route('admin.reports.technician.export')}
                                params={filters}
                                canExport={can.export}
                            />
                        ) : undefined
                    }
                />
                <Card>
                    <CardContent className="space-y-4 pt-6">
                        <form
                            onSubmit={submit}
                            className="flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-end"
                        >
                            {!isSelf && (
                                <div className="w-full min-w-0 sm:w-64">
                                    <RemoteSearchSelect
                                        field="technician"
                                        options={technicianOptions}
                                        selected={selectedTechnician}
                                        label="Teknisi"
                                        placeholder="Cari teknisi..."
                                        value={formData.technician_id}
                                        onChange={(value) => setData('technician_id', value)}
                                        error={errors.technician_id}
                                        required
                                    />
                                </div>
                            )}
                            <div className="w-full min-w-0 sm:w-80">
                                <DateRangePicker
                                    label="Periode"
                                    value={{
                                        from: formData.date_from || null,
                                        to: formData.date_to || null,
                                    }}
                                    onChange={({ from, to }) =>
                                        setData((values) => ({
                                            ...values,
                                            date_from: from ?? '',
                                            date_to: to ?? '',
                                        }))
                                    }
                                    error={errors.date_from ?? errors.date_to}
                                />
                            </div>
                            <Button
                                type="submit"
                                loading={processing}
                                disabled={!isSelf && formData.technician_id === ''}
                            >
                                Tampilkan
                            </Button>
                        </form>
                    </CardContent>
                </Card>
                {showMetrics && data ? (
                    <div className="grid gap-4 md:grid-cols-3">
                        <Card>
                            <CardHeader>
                                <CardTitle>{meta.metrics.spk_completed.label}</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <p className="text-2xl font-bold">{data.spk_completed ?? 0}</p>
                                <DrillLink
                                    href={drill.spk_completed}
                                    label={meta.metrics.spk_completed.label}
                                />
                            </CardContent>
                        </Card>
                        <Card>
                            <CardHeader>
                                <CardTitle>{meta.metrics.tickets_resolved.label}</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <p className="text-2xl font-bold">{data.tickets_resolved ?? 0}</p>
                                <DrillLink
                                    href={drill.tickets_resolved}
                                    label={meta.metrics.tickets_resolved.label}
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
                            </CardContent>
                        </Card>
                        <Card>
                            <CardHeader>
                                <CardTitle>{meta.metrics.avg_score.label}</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <p className="text-2xl font-bold">{data.avg_score ?? '-'}</p>
                                <DrillLink
                                    href={drill.avg_score}
                                    label={meta.metrics.avg_score.label}
                                />
                            </CardContent>
                        </Card>
                        <Card>
                            <CardHeader>
                                <CardTitle>{meta.metrics.avg_frt_minutes.label}</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <p className="text-2xl font-bold">{data.avg_frt_minutes ?? '-'}</p>
                                <DrillLink
                                    href={drill.avg_frt_minutes}
                                    label={meta.metrics.avg_frt_minutes.label}
                                />
                            </CardContent>
                        </Card>
                        <Card>
                            <CardHeader>
                                <CardTitle>{meta.metrics.active_workload.label}</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <p className="text-2xl font-bold">{data.active_workload ?? 0}</p>
                                <DrillLink
                                    href={drill.active_workload_spk}
                                    label={`${meta.metrics.active_workload.label} (SPK)`}
                                />
                                <DrillLink
                                    href={drill.active_workload_tickets}
                                    label={`${meta.metrics.active_workload.label} (tiket)`}
                                />
                            </CardContent>
                        </Card>
                    </div>
                ) : (
                    !isSelf && (
                        <p className="text-sm text-muted-foreground">
                            {noTechnicians
                                ? 'Tidak ada teknisi yang bisa dipilih.'
                                : 'Pilih teknisi untuk melihat laporan.'}
                        </p>
                    )
                )}
                {showMetrics && data && charts && (
                    <TechnicianCharts
                        charts={charts}
                        spkByType={data.spk_by_type}
                        ticketsByCategory={data.tickets_by_category}
                    />
                )}
            </div>
        </AdminLayout>
    );
}
