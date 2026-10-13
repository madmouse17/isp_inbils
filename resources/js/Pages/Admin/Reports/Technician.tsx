import type { FormEvent } from 'react';
import { useEffect } from 'react';
import { useForm } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import { PageHeader } from '@/Components/composite';
import {
    Button,
    Card,
    CardContent,
    CardHeader,
    CardTitle,
    Input,
    NativeSelect,
} from '@/Components/ui';
import { DrillLink, type DrillLinks } from './components/DrillLink';
import { ReportExportMenu } from './components/ReportExportMenu';

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
}

interface TechRow {
    id: number;
    name: string;
}

interface Props extends Record<string, unknown> {
    data?: TechData | null;
    technicians?: { data?: unknown } | null;
    filters: { technician_id?: string; date_from?: string; date_to?: string };
    can: { export: boolean };
    drill: DrillLinks;
}

export default function Technician({ data, technicians, filters, can, drill }: Props) {
    const isSelf =
        typeof data?.technician_id === 'number' &&
        Number.isFinite(data.technician_id) &&
        filters.technician_id === undefined;
    const eligibleTechnicians: TechRow[] = (() => {
        if (!Array.isArray(technicians?.data)) return [];

        return technicians.data.filter(
            (row: unknown): row is TechRow =>
                typeof row === 'object' &&
                row !== null &&
                typeof (row as TechRow).id === 'number' &&
                Number.isFinite((row as TechRow).id) &&
                (row as TechRow).id > 0 &&
                typeof (row as TechRow).name === 'string' &&
                (row as TechRow).name.trim().length > 0,
        );
    })();
    const serverTarget = eligibleTechnicians.find(
        (row) => String(row.id) === String(filters.technician_id),
    )?.id;
    const {
        data: formData,
        setData,
        transform,
        get,
        processing,
        errors,
    } = useForm({
        technician_id: serverTarget === undefined ? '' : String(serverTarget),
        date_from: filters.date_from ?? '',
        date_to: filters.date_to ?? '',
    });

    useEffect(() => {
        setData({
            technician_id: serverTarget === undefined ? '' : String(serverTarget),
            date_from: filters.date_from ?? '',
            date_to: filters.date_to ?? '',
        });
    }, [filters.technician_id, filters.date_from, filters.date_to, serverTarget, setData]);

    const selectedTarget = eligibleTechnicians.find(
        (row) => String(row.id) === formData.technician_id,
    )?.id;
    const showMetrics = Boolean(
        data &&
        (isSelf ||
            (serverTarget !== undefined &&
                data.technician_id === serverTarget &&
                formData.technician_id === String(serverTarget))),
    );

    const submit = (e: FormEvent) => {
        e.preventDefault();
        if (!isSelf && selectedTarget === undefined) return;

        transform((values) =>
            isSelf
                ? { date_from: values.date_from, date_to: values.date_to }
                : {
                      technician_id: selectedTarget,
                      date_from: values.date_from,
                      date_to: values.date_to,
                  },
        );
        get(route('admin.reports.technician'), { preserveState: true });
    };

    return (
        <AdminLayout title="Kinerja Teknisi">
            <div className="space-y-6">
                <PageHeader
                    title="Kinerja Teknisi"
                    subtitle="SPK/ticket counts, avg completion, SLA, rating, and FRT."
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
                                <div className="w-full min-w-0 sm:w-48">
                                    <NativeSelect
                                        label="Technician"
                                        value={formData.technician_id}
                                        onChange={(e) => setData('technician_id', e.target.value)}
                                        error={errors.technician_id}
                                    >
                                        <option value="">Select...</option>
                                        {eligibleTechnicians.map((row) => (
                                            <option key={row.id} value={row.id}>
                                                {row.name}
                                            </option>
                                        ))}
                                    </NativeSelect>
                                </div>
                            )}
                            <div className="w-full min-w-0 sm:w-48">
                                <Input
                                    label="From"
                                    type="date"
                                    value={formData.date_from}
                                    onChange={(e) => setData('date_from', e.target.value)}
                                    error={errors.date_from}
                                />
                            </div>
                            <div className="w-full min-w-0 sm:w-48">
                                <Input
                                    label="To"
                                    type="date"
                                    value={formData.date_to}
                                    onChange={(e) => setData('date_to', e.target.value)}
                                    error={errors.date_to}
                                />
                            </div>
                            <Button
                                type="submit"
                                loading={processing}
                                disabled={!isSelf && selectedTarget === undefined}
                            >
                                Run
                            </Button>
                        </form>
                    </CardContent>
                </Card>
                {showMetrics && data ? (
                    <div className="grid gap-4 md:grid-cols-3">
                        <Card>
                            <CardHeader>
                                <CardTitle>SPK Completed</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <p className="text-2xl font-bold">{data.spk_completed ?? 0}</p>
                                <DrillLink href={drill.spk_completed} label="SPK Completed" />
                            </CardContent>
                        </Card>
                        <Card>
                            <CardHeader>
                                <CardTitle>Tickets Resolved</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <p className="text-2xl font-bold">{data.tickets_resolved ?? 0}</p>
                                <DrillLink href={drill.tickets_resolved} label="Tickets Resolved" />
                            </CardContent>
                        </Card>
                        <Card>
                            <CardHeader>
                                <CardTitle>SLA Compliance</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <p className="text-2xl font-bold">
                                    {data.sla_compliance_pct ?? 0}%
                                </p>
                                <DrillLink href={drill.sla_compliance_pct} label="SLA Compliance" />
                            </CardContent>
                        </Card>
                        <Card>
                            <CardHeader>
                                <CardTitle>Avg Score</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <p className="text-2xl font-bold">{data.avg_score ?? '-'}</p>
                                <DrillLink href={drill.avg_score} label="Avg Score" />
                            </CardContent>
                        </Card>
                        <Card>
                            <CardHeader>
                                <CardTitle>Avg FRT (min)</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <p className="text-2xl font-bold">{data.avg_frt_minutes ?? '-'}</p>
                                <DrillLink href={drill.avg_frt_minutes} label="Avg FRT" />
                            </CardContent>
                        </Card>
                        <Card>
                            <CardHeader>
                                <CardTitle>Active Workload</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <p className="text-2xl font-bold">{data.active_workload ?? 0}</p>
                                <DrillLink
                                    href={drill.active_workload_spk}
                                    label="Active Workload SPK"
                                />
                                <DrillLink
                                    href={drill.active_workload_tickets}
                                    label="Active Workload tickets"
                                />
                            </CardContent>
                        </Card>
                    </div>
                ) : (
                    !isSelf && (
                        <p className="text-sm text-muted-foreground">
                            {eligibleTechnicians.length === 0
                                ? 'Tidak ada teknisi yang dapat dipilih.'
                                : 'Pilih teknisi untuk melihat laporan.'}
                        </p>
                    )
                )}
            </div>
        </AdminLayout>
    );
}
