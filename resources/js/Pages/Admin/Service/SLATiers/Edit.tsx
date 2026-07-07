import { FormEvent } from 'react';
import { router, useForm } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import { Button, Card, CardContent, CardHeader, CardTitle, Input, Switch } from '@/Components/ui';
import { PageHeader } from '@/Components/composite';

interface SlaData {
    id: number; name: string; uptime_pct: string; response_time_hours: number;
    resolution_time_hours: number; credit_pct: string; is_active: boolean;
}

interface EditProps extends Record<string, unknown> {
    slaTier: { data: SlaData };
}

export default function Edit({ slaTier }: EditProps) {
    const s = slaTier.data;
    const { data, setData, put, processing, errors } = useForm({
        name: s.name,
        uptime_pct: s.uptime_pct,
        response_time_hours: String(s.response_time_hours),
        resolution_time_hours: String(s.resolution_time_hours),
        credit_pct: s.credit_pct,
        is_active: s.is_active,
    });

    const submit = (e: FormEvent) => { e.preventDefault(); put(route('admin.sla-tiers.update', s.id)); };

    return (
        <AdminLayout title="Edit SLA Tier">
            <div className="space-y-6">
                <PageHeader title="Edit SLA Tier" subtitle="Fill required fields, then save." />
                <form onSubmit={submit} className="space-y-6">
                    <Card>
                        <CardHeader><CardTitle>Details</CardTitle></CardHeader>
                        <CardContent className="grid gap-4 md:grid-cols-2">
                            <Input label="Name" value={data.name} onChange={(e) => setData('name', e.target.value)} error={errors.name} required />
                            <Input label="Uptime (%)" type="number" step="0.01" value={data.uptime_pct} onChange={(e) => setData('uptime_pct', e.target.value)} error={errors.uptime_pct} required />
                            <Input label="Response Time (hours)" type="number" value={data.response_time_hours} onChange={(e) => setData('response_time_hours', e.target.value)} error={errors.response_time_hours} required />
                            <Input label="Resolution Time (hours)" type="number" value={data.resolution_time_hours} onChange={(e) => setData('resolution_time_hours', e.target.value)} error={errors.resolution_time_hours} required />
                            <Input label="Credit (%)" type="number" step="0.01" value={data.credit_pct} onChange={(e) => setData('credit_pct', e.target.value)} error={errors.credit_pct} required />
                            <div className="flex items-end"><Switch label="Active" checked={data.is_active} onCheckedChange={(c) => setData('is_active', c)} /></div>
                        </CardContent>
                    </Card>
                    <div className="flex justify-end gap-2">
                        <Button type="button" variant="outline" onClick={() => router.get(route('admin.sla-tiers.index'))}>Cancel</Button>
                        <Button type="submit" loading={processing}>Save</Button>
                    </div>
                </form>
            </div>
        </AdminLayout>
    );
}
