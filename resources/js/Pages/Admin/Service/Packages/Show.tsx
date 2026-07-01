import { router } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import { Badge, Button, Card, CardContent, CardHeader, CardTitle } from '@/Components/ui';

interface PkgData {
    id: number; code: string; name: string; price_mrc: string; price_otc: string;
    contract_min_months: number | null; description: string | null; is_active: boolean;
    bandwidth_profile: { name: string } | null;
    speed_profile: { name: string } | null;
    sla_tier: { name: string } | null;
}

interface ShowProps extends Record<string, unknown> {
    servicePackage: { data: PkgData };
}

export default function Show({ servicePackage }: ShowProps) {
    const p = servicePackage.data;

    return (
        <AdminLayout title={p.name}>
            <div className="space-y-6">
                <div className="flex items-center justify-between gap-4">
                    <div>
                        <h2 className="text-2xl font-bold text-surface-900 dark:text-surface-100">{p.name}</h2>
                        <p className="mt-1 text-sm text-surface-500 dark:text-surface-400">{p.code}</p>
                    </div>
                    <div className="flex gap-2">
                        <Button type="button" variant="secondary" onClick={() => router.get(route('admin.service-packages.edit', p.id))}>Edit</Button>
                        <Button type="button" variant="secondary" onClick={() => router.get(route('admin.service-packages.index'))}>Back</Button>
                    </div>
                </div>
                <Card>
                    <CardHeader><CardTitle>Package Details</CardTitle></CardHeader>
                    <CardContent className="grid gap-4 text-sm md:grid-cols-2">
                        <p><span className="text-surface-500 dark:text-surface-400">Bandwidth: </span>{p.bandwidth_profile?.name ?? '-'}</p>
                        <p><span className="text-surface-500 dark:text-surface-400">Speed: </span>{p.speed_profile?.name ?? '-'}</p>
                        <p><span className="text-surface-500 dark:text-surface-400">SLA: </span>{p.sla_tier?.name ?? '-'}</p>
                        <p><span className="text-surface-500 dark:text-surface-400">MRC: </span>{p.price_mrc}</p>
                        <p><span className="text-surface-500 dark:text-surface-400">OTC: </span>{p.price_otc}</p>
                        <p><span className="text-surface-500 dark:text-surface-400">Contract Min: </span>{p.contract_min_months ? `${p.contract_min_months} months` : '-'}</p>
                        <p><span className="text-surface-500 dark:text-surface-400">Status: </span><Badge variant={p.is_active ? 'success' : 'danger'}>{p.is_active ? 'Active' : 'Inactive'}</Badge></p>
                        {p.description && <p className="md:col-span-2"><span className="text-surface-500 dark:text-surface-400">Description: </span>{p.description}</p>}
                    </CardContent>
                </Card>
            </div>
        </AdminLayout>
    );
}
