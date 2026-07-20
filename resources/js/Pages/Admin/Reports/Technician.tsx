import type { FormEvent } from 'react';
import { useState } from 'react';
import { router } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import { PageHeader } from '@/Components/composite';
import { Button, Card, CardContent, CardHeader, CardTitle, Input, Select } from '@/Components/ui';

interface TechData extends Record<string, unknown> {
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
    data?: TechData;
    technicians: { data: TechRow[] };
    filters: { technician_id?: string; date_from?: string; date_to?: string };
}

export default function Technician({ data, technicians, filters }: Props) {
    const [techId, setTechId] = useState(filters.technician_id ?? '');
    const [dateFrom, setDateFrom] = useState(filters.date_from ?? '');
    const [dateTo, setDateTo] = useState(filters.date_to ?? '');

    const submit = (e: FormEvent) => {
        e.preventDefault();
        router.get(
            route('admin.reports.technician'),
            { technician_id: techId, date_from: dateFrom, date_to: dateTo },
            { preserveState: true },
        );
    };

    return (
        <AdminLayout title="Technician Performance">
            <div className="space-y-6">
                <PageHeader
                    title="Technician Performance"
                    subtitle="SPK/ticket counts, avg completion, SLA, rating, and FRT."
                />
                <Card>
                    <CardContent className="space-y-4 pt-6">
                        <form onSubmit={submit} className="flex flex-wrap gap-2">
                            <Select
                                label="Technician"
                                value={techId}
                                onChange={(e) => setTechId(e.target.value)}
                            >
                                <option value="">Select...</option>
                                {technicians.data.map((t) => (
                                    <option key={t.id} value={t.id}>
                                        {t.name}
                                    </option>
                                ))}
                            </Select>
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
                                <Button type="submit">Run</Button>
                            </div>
                        </form>
                    </CardContent>
                </Card>
                {data && (
                    <div className="grid gap-4 md:grid-cols-3">
                        <Card>
                            <CardHeader>
                                <CardTitle>SPK Completed</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <p className="text-2xl font-bold">{data.spk_completed ?? 0}</p>
                            </CardContent>
                        </Card>
                        <Card>
                            <CardHeader>
                                <CardTitle>Tickets Resolved</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <p className="text-2xl font-bold">{data.tickets_resolved ?? 0}</p>
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
                            </CardContent>
                        </Card>
                        <Card>
                            <CardHeader>
                                <CardTitle>Avg Score</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <p className="text-2xl font-bold">{data.avg_score ?? '-'}</p>
                            </CardContent>
                        </Card>
                        <Card>
                            <CardHeader>
                                <CardTitle>Avg FRT (min)</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <p className="text-2xl font-bold">{data.avg_frt_minutes ?? '-'}</p>
                            </CardContent>
                        </Card>
                        <Card>
                            <CardHeader>
                                <CardTitle>Active Workload</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <p className="text-2xl font-bold">{data.active_workload ?? 0}</p>
                            </CardContent>
                        </Card>
                    </div>
                )}
            </div>
        </AdminLayout>
    );
}
