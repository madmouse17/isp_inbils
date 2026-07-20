import type { FormEvent } from 'react';
import { useState } from 'react';
import { router } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import { PageHeader } from '@/Components/composite';
import { Button, Card, CardContent, Input, Table, TBody, TD, TH, THead, TR } from '@/Components/ui';

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
    filters: { date_from?: string; date_to?: string };
}

export default function Sla({ data, filters }: Props) {
    const [dateFrom, setDateFrom] = useState(filters.date_from ?? '');
    const [dateTo, setDateTo] = useState(filters.date_to ?? '');

    const submit = (e: FormEvent) => {
        e.preventDefault();
        router.get(
            route('admin.reports.sla'),
            { date_from: dateFrom, date_to: dateTo },
            { preserveState: true },
        );
    };

    return (
        <AdminLayout title="SLA Compliance">
            <div className="space-y-6">
                <PageHeader
                    title="SLA Compliance"
                    subtitle="Resolution rate, breach count, and per-category breakdown."
                />
                <Card>
                    <CardContent className="space-y-4 pt-6">
                        <form onSubmit={submit} className="flex gap-2">
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
                    <div className="space-y-4">
                        <div className="grid gap-4 md:grid-cols-4">
                            <Card>
                                <CardContent>
                                    <p className="text-sm text-surface-500">Total Resolved</p>
                                    <p className="text-2xl font-bold">{data.total_resolved ?? 0}</p>
                                </CardContent>
                            </Card>
                            <Card>
                                <CardContent>
                                    <p className="text-sm text-surface-500">SLA Rate</p>
                                    <p className="text-2xl font-bold">{data.sla_rate ?? 0}%</p>
                                </CardContent>
                            </Card>
                            <Card>
                                <CardContent>
                                    <p className="text-sm text-surface-500">Breaches</p>
                                    <p className="text-2xl font-bold">{data.breach_count ?? 0}</p>
                                </CardContent>
                            </Card>
                            <Card>
                                <CardContent>
                                    <p className="text-sm text-surface-500">Avg Resolution</p>
                                    <p className="text-2xl font-bold">
                                        {data.avg_resolution_minutes ?? '-'} min
                                    </p>
                                </CardContent>
                            </Card>
                        </div>
                        <Card>
                            <CardContent className="space-y-4 pt-6">
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
                                                    <TD>{c.total}</TD>
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
