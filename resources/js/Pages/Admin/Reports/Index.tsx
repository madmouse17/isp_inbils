import AdminLayout from '@/Layouts/AdminLayout';
import { PageHeader } from '@/Components/composite';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui';
import { router } from '@inertiajs/react';

const reports = [
    { key: 'business', title: 'Business Metrics', desc: 'MRR, revenue, churn, outstanding, asset utilization' },
    { key: 'technician', title: 'Technician Performance', desc: 'SPK/ticket counts, avg completion, SLA, rating, FRT' },
    { key: 'asset', title: 'Asset Utilization', desc: 'Status distribution, per-location, installation history' },
    { key: 'sla', title: 'SLA Compliance', desc: 'Resolution rate, breach count, per-category breakdown' },
    { key: 'stock-card', title: 'Stock Card', desc: 'Movement history per product/location' },
    { key: 'audit-log', title: 'Audit Log', desc: 'Activity trail by user/module/date' },
];

export default function Index() {
    return (
        <AdminLayout title="Reports">
            <div className="space-y-6">
                <PageHeader title="Reports" subtitle="Real-time read-only reports from workflow history." />
                <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
                    {reports.map((r) => (
                        <Card key={r.key} className="cursor-pointer hover:ring-2 hover:ring-brand-500/40" >
                            <CardContent onClick={() => router.get(route(`admin.reports.${r.key}`))} >
                                <CardHeader><CardTitle>{r.title}</CardTitle></CardHeader>
                                <p className="text-sm text-surface-500 dark:text-surface-400">{r.desc}</p>
                            </CardContent>
                        </Card>
                    ))}
                </div>
            </div>
        </AdminLayout>
    );
}
