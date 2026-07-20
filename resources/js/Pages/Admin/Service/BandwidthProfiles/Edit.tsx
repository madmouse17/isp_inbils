import type { FormEvent } from 'react';
import { router, useForm } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import {
    Button,
    Card,
    CardContent,
    CardHeader,
    CardTitle,
    Input,
    Select,
    Switch,
} from '@/Components/ui';
import { PageHeader } from '@/Components/composite';

interface BwData {
    id: number;
    name: string;
    download_mbps: number;
    upload_mbps: number;
    type: string;
    contention_ratio: number;
    is_active: boolean;
}

interface EditProps extends Record<string, unknown> {
    bandwidthProfile: { data: BwData };
}

export default function Edit({ bandwidthProfile }: EditProps) {
    const b = bandwidthProfile.data;
    const { data, setData, put, processing, errors } = useForm({
        name: b.name,
        download_mbps: String(b.download_mbps),
        upload_mbps: String(b.upload_mbps),
        type: b.type,
        contention_ratio: String(b.contention_ratio),
        is_active: b.is_active,
    });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        put(route('admin.bandwidth-profiles.update', b.id));
    };

    return (
        <AdminLayout title="Edit Bandwidth Profile">
            <div className="space-y-6">
                <PageHeader
                    title="Edit Bandwidth Profile"
                    subtitle="Fill required fields, then save."
                />
                <form onSubmit={submit} className="space-y-6">
                    <Card>
                        <CardHeader>
                            <CardTitle>Details</CardTitle>
                        </CardHeader>
                        <CardContent className="grid gap-4 md:grid-cols-2">
                            <Input
                                label="Name"
                                value={data.name}
                                onChange={(e) => setData('name', e.target.value)}
                                error={errors.name}
                                required
                            />
                            <Select
                                label="Type"
                                value={data.type}
                                onChange={(e) => setData('type', e.target.value)}
                            >
                                <option value="shared">Shared</option>
                                <option value="dedicated">Dedicated</option>
                            </Select>
                            <Input
                                label="Download (Mbps)"
                                type="number"
                                value={data.download_mbps}
                                onChange={(e) => setData('download_mbps', e.target.value)}
                                error={errors.download_mbps}
                                required
                            />
                            <Input
                                label="Upload (Mbps)"
                                type="number"
                                value={data.upload_mbps}
                                onChange={(e) => setData('upload_mbps', e.target.value)}
                                error={errors.upload_mbps}
                                required
                            />
                            <Input
                                label="Contention Ratio"
                                type="number"
                                value={data.contention_ratio}
                                onChange={(e) => setData('contention_ratio', e.target.value)}
                                error={errors.contention_ratio}
                                required
                            />
                            <div className="flex items-end">
                                <Switch
                                    label="Active"
                                    checked={data.is_active}
                                    onCheckedChange={(c) => setData('is_active', c)}
                                />
                            </div>
                        </CardContent>
                    </Card>
                    <div className="flex justify-end gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => router.get(route('admin.bandwidth-profiles.index'))}
                        >
                            Cancel
                        </Button>
                        <Button type="submit" loading={processing}>
                            Save
                        </Button>
                    </div>
                </form>
            </div>
        </AdminLayout>
    );
}
