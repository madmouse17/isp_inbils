import { FormEvent } from 'react';
import { router, useForm } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import { Button, Card, CardContent, CardFooter, CardHeader, CardTitle, Input, Select, Switch } from '@/Components/ui';

export default function Create() {
    const { data, setData, post, processing, errors } = useForm({
        name: '',
        download_mbps: '',
        upload_mbps: '',
        type: 'shared' as string,
        contention_ratio: '1',
        is_active: true,
    });

    const submit = (e: FormEvent) => { e.preventDefault(); post(route('admin.bandwidth-profiles.store')); };

    return (
        <AdminLayout title="Create Bandwidth Profile">
            <form onSubmit={submit}>
                <Card>
                    <CardHeader><CardTitle>Create Bandwidth Profile</CardTitle></CardHeader>
                    <CardContent className="space-y-4">
                        <div className="grid gap-4 md:grid-cols-2">
                            <Input label="Name" value={data.name} onChange={(e) => setData('name', e.target.value)} error={errors.name} required />
                            <Select label="Type" value={data.type} onChange={(e) => setData('type', e.target.value)}>
                                <option value="shared">Shared</option>
                                <option value="dedicated">Dedicated</option>
                            </Select>
                            <Input label="Download (Mbps)" type="number" value={data.download_mbps} onChange={(e) => setData('download_mbps', e.target.value)} error={errors.download_mbps} required />
                            <Input label="Upload (Mbps)" type="number" value={data.upload_mbps} onChange={(e) => setData('upload_mbps', e.target.value)} error={errors.upload_mbps} required />
                            <Input label="Contention Ratio" type="number" value={data.contention_ratio} onChange={(e) => setData('contention_ratio', e.target.value)} error={errors.contention_ratio} required />
                            <div className="flex items-end"><Switch label="Active" checked={data.is_active} onCheckedChange={(c) => setData('is_active', c)} /></div>
                        </div>
                    </CardContent>
                    <CardFooter className="justify-end gap-2">
                        <Button type="button" variant="secondary" onClick={() => router.get(route('admin.bandwidth-profiles.index'))}>Cancel</Button>
                        <Button type="submit" loading={processing}>Create</Button>
                    </CardFooter>
                </Card>
            </form>
        </AdminLayout>
    );
}
