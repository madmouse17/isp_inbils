import type { FormEvent, ReactNode } from 'react';
import { useEffect } from 'react';
import { Link, useForm } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import { PageHeader } from '@/Components/composite';
import { RemoteSearchSelect, type RemoteOption } from '@/Components/composite/RemoteSearchSelect';
import {
    Button,
    Card,
    CardContent,
    CardHeader,
    CardTitle,
    DateRangePicker,
    Table,
    TBody,
    TD,
    TH,
    THead,
    TR,
} from '@/Components/ui';
import { formatDecimal, formatNumber, formatPercent } from '@/lib/format';
import type { ReportMeta, TechnicianChartData, TechnicianRankingRow } from '@/types';
import { DrillLink, type DrillLinks } from './components/DrillLink';
import { formatMinutes } from './components/formatMinutes';
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
    technician_id?: string;
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
    /** Every technician of the viewer's scope ranked for the period; null once one is picked. */
    ranking: TechnicianRankingRow[] | null;
    technicianOptions?: RemoteOption[];
    selectedTechnician: RemoteOption | null;
    hasTechnicians: boolean;
    filters: { technician_id?: string; date_from?: string; date_to?: string };
    meta: ReportMeta<TechnicianMetricKey>;
    can: { export: boolean };
    drill: DrillLinks;
}

/** A figure of a card with what it counts, so a bare "66" never stands alone. */
function Figure({ children, unit }: { children: ReactNode; unit?: string }) {
    return (
        <p className="text-2xl font-bold">
            {children}
            {unit && <span className="ml-1 text-sm font-normal text-muted-foreground">{unit}</span>}
        </p>
    );
}

export default function Technician({
    data,
    charts,
    ranking,
    technicianOptions,
    selectedTechnician,
    hasTechnicians,
    filters,
    meta,
    can,
    drill,
}: Props) {
    const isSelf =
        typeof data?.technician_id === 'string' &&
        data.technician_id !== '' &&
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

        // Without a technician the period alone is asked for, and the ranking of everyone answers it.
        transform((values) => ({
            ...(isSelf || values.technician_id === ''
                ? {}
                : { technician_id: values.technician_id }),
            date_from: values.date_from,
            date_to: values.date_to,
        }));
        get(route('admin.reports.technician'), { preserveState: true });
    };

    const detailUrl = (technicianId: string) =>
        route('admin.reports.technician', {
            technician_id: technicianId,
            ...(filters.date_from ? { date_from: filters.date_from } : {}),
            ...(filters.date_to ? { date_to: filters.date_to } : {}),
        });

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
                                        placeholder="Semua teknisi"
                                        value={formData.technician_id}
                                        onChange={(value) => setData('technician_id', value)}
                                        error={errors.technician_id}
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
                            <Button type="submit" loading={processing}>
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
                                <Figure unit="SPK">{formatNumber(data.spk_completed ?? 0)}</Figure>
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
                                <Figure unit="tiket">
                                    {formatNumber(data.tickets_resolved ?? 0)}
                                </Figure>
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
                                <Figure>{formatPercent(data.sla_compliance_pct)}</Figure>
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
                                <Figure unit={data.avg_score == null ? undefined : 'dari 5'}>
                                    {formatDecimal(data.avg_score)}
                                </Figure>
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
                                <Figure>{formatMinutes(data.avg_frt_minutes)}</Figure>
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
                                <Figure unit="pekerjaan">
                                    {formatNumber(data.active_workload ?? 0)}
                                </Figure>
                                <DrillLink
                                    href={drill.active_workload_spk}
                                    label={meta.metrics.active_workload.label}
                                    text="Lihat SPK"
                                />
                                <DrillLink
                                    href={drill.active_workload_tickets}
                                    label={meta.metrics.active_workload.label}
                                    text="Lihat tiket"
                                />
                            </CardContent>
                        </Card>
                    </div>
                ) : (
                    !isSelf &&
                    (noTechnicians || !ranking || ranking.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            Tidak ada teknisi yang bisa dipilih.
                        </p>
                    ) : (
                        <Card>
                            <CardContent className="space-y-4 pt-6">
                                <div className="min-w-0">
                                    <p className="font-medium">Peringkat teknisi</p>
                                    <p className="text-sm text-muted-foreground">
                                        Menurut jumlah SPK dan tiket yang selesai pada periode.
                                        Pilih satu teknisi untuk melihat rinciannya.
                                    </p>
                                </div>
                                <Table>
                                    <THead>
                                        <TR>
                                            <TH>Peringkat</TH>
                                            <TH>Teknisi</TH>
                                            <TH className="text-right">SPK selesai</TH>
                                            <TH className="text-right">Tiket diselesaikan</TH>
                                            <TH className="text-right">Kepatuhan SLA</TH>
                                            <TH className="text-right">Respons pertama</TH>
                                            <TH className="text-right">Skor evaluasi</TH>
                                            <TH className="text-right">Beban aktif</TH>
                                        </TR>
                                    </THead>
                                    <TBody>
                                        {ranking.map((row) => (
                                            <TR key={row.id}>
                                                <TD className="tabular-nums">{row.rank}</TD>
                                                <TD>
                                                    <Link
                                                        href={detailUrl(row.id)}
                                                        className="rounded-sm font-medium text-primary-text underline underline-offset-2 focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background focus-visible:outline-hidden"
                                                    >
                                                        {row.name}
                                                    </Link>
                                                </TD>
                                                <TD className="text-right tabular-nums">
                                                    {formatNumber(row.spk_completed)}
                                                </TD>
                                                <TD className="text-right tabular-nums">
                                                    {formatNumber(row.tickets_resolved)}
                                                </TD>
                                                <TD className="text-right tabular-nums">
                                                    {formatPercent(row.sla_compliance_pct)}
                                                </TD>
                                                <TD className="text-right whitespace-nowrap tabular-nums">
                                                    {formatMinutes(row.avg_frt_minutes)}
                                                </TD>
                                                <TD className="text-right tabular-nums">
                                                    {formatDecimal(row.avg_score)}
                                                </TD>
                                                <TD className="text-right tabular-nums">
                                                    {formatNumber(row.active_workload)}
                                                </TD>
                                            </TR>
                                        ))}
                                    </TBody>
                                </Table>
                            </CardContent>
                        </Card>
                    ))
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
