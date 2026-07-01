import { FormEvent, useState } from 'react';
import { router } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import { Button, Card, CardContent, CardHeader, CardTitle, Input, Table, TBody, TD, TH, THead, TR } from '@/Components/ui';

interface BusinessData extends Record<string, unknown> {
    mrr?: string; active_subscriptions?: number; suspended_subscriptions?: number;
    terminated_subscriptions?: number; new_subscriptions?: number; churn?: number;
    revenue_paid?: string; recurring_revenue?: string; one_time_revenue?: string;
    outstanding?: string; sla_compliance_pct?: number; installation_count?: number;
    asset_distribution?: Record<string, number>;
}

interface Props extends Record<string, unknown> {
    data?: BusinessData;
    filters: { date_from?: string; date_to?: string };
}

export default function Business({ data, filters }: Props) {
    const [dateFrom, setDateFrom] = useState(filters.date_from ?? '');
    const [dateTo, setDateTo] = useState(filters.date_to ?? '');

    const submit = (e: FormEvent) => {
        e.preventDefault();
        router.get(route('admin.reports.business'), { date_from: dateFrom, date_to: dateTo }, { preserveState: true });
    };

    return (
        <AdminLayout title="Business Metrics">
            <div className="space-y-6">
                <h2 className="text-2xl font-bold text-surface-900 dark:text-surface-100">Business Metrics</h2>
                <Card>
                    <CardContent>
                        <form onSubmit={submit} className="flex gap-2">
                            <Input label="From" type="date" value={dateFrom} onChange={(e) => setDateFrom(e.target.value)} />
                            <Input label="To" type="date" value={dateTo} onChange={(e) => setDateTo(e.target.value)} />
                            <div className="self-end"><Button type="submit">Run</Button></div>
                        </form>
                    </CardContent>
                </Card>
                {data && (
                    <div className="grid gap-4 md:grid-cols-3">
                        <Card><CardHeader><CardTitle>MRR</CardTitle></CardHeader><CardContent><p className="text-2xl font-bold">{data.mrr ?? '0'}</p></CardContent></Card>
                        <Card><CardHeader><CardTitle>Revenue (Paid)</CardTitle></CardHeader><CardContent><p className="text-2xl font-bold">{data.revenue_paid ?? '0'}</p></CardContent></Card>
                        <Card><CardHeader><CardTitle>Outstanding</CardTitle></CardHeader><CardContent><p className="text-2xl font-bold">{data.outstanding ?? '0'}</p></CardContent></Card>
                        <Card><CardHeader><CardTitle>Active Subs</CardTitle></CardHeader><CardContent><p className="text-2xl font-bold">{data.active_subscriptions ?? 0}</p></CardContent></Card>
                        <Card><CardHeader><CardTitle>Suspended</CardTitle></CardHeader><CardContent><p className="text-2xl font-bold">{data.suspended_subscriptions ?? 0}</p></CardContent></Card>
                        <Card><CardHeader><CardTitle>SLA Compliance</CardTitle></CardHeader><CardContent><p className="text-2xl font-bold">{data.sla_compliance_pct ?? 0}%</p></CardContent></Card>
                    </div>
                )}
            </div>
        </AdminLayout>
    );
}
