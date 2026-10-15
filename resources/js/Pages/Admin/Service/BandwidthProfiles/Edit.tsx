import type { FormEvent } from 'react';
import { Link, useForm } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import {
    Button,
    Card,
    CardContent,
    CardHeader,
    CardTitle,
    Input,
    NativeSelect,
    Switch,
} from '@/Components/ui';
import { PageHeader } from '@/Components/composite';
import { BANDWIDTH_TYPE, optionsOf } from '@/lib/statuses';

interface BwData {
    id: number;
    name: string;
    download_mbps: number;
    upload_mbps: number;
    type: string;
    /** Empty for a dedicated profile. */
    contention_ratio: number | null;
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
        contention_ratio: b.contention_ratio === null ? '' : String(b.contention_ratio),
        is_active: b.is_active,
    });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        put(route('admin.bandwidth-profiles.update', b.id));
    };

    return (
        <AdminLayout title="Ubah Profil Bandwidth">
            <div className="space-y-6">
                <PageHeader
                    title="Ubah Profil Bandwidth"
                    subtitle="Ubah data profil, lalu simpan."
                    actions={
                        <Button
                            asChild
                            variant="outline"
                            size="sm"
                            leftIcon={<ArrowLeft className="size-4" />}
                        >
                            <Link href={route('admin.bandwidth-profiles.index')}>Kembali</Link>
                        </Button>
                    }
                />
                <form onSubmit={submit} className="space-y-6">
                    <Card>
                        <CardHeader>
                            <CardTitle>Data profil</CardTitle>
                        </CardHeader>
                        <CardContent className="grid gap-4 md:grid-cols-2">
                            <Input
                                label="Nama"
                                value={data.name}
                                onChange={(e) => setData('name', e.target.value)}
                                error={errors.name}
                                required
                            />
                            <NativeSelect
                                label="Tipe"
                                value={data.type}
                                onChange={(e) => setData('type', e.target.value)}
                                error={errors.type}
                                options={optionsOf(BANDWIDTH_TYPE)}
                            />
                            <Input
                                label="Unduh (Mbps)"
                                type="number"
                                value={data.download_mbps}
                                onChange={(e) => setData('download_mbps', e.target.value)}
                                error={errors.download_mbps}
                                required
                            />
                            <Input
                                label="Unggah (Mbps)"
                                type="number"
                                value={data.upload_mbps}
                                onChange={(e) => setData('upload_mbps', e.target.value)}
                                error={errors.upload_mbps}
                                required
                            />
                            <Input
                                label="Rasio kontensi"
                                type="number"
                                value={data.contention_ratio}
                                onChange={(e) => setData('contention_ratio', e.target.value)}
                                error={errors.contention_ratio}
                                hint={
                                    data.type === 'shared'
                                        ? undefined
                                        : 'Tidak dipakai untuk profil dedikasi.'
                                }
                                disabled={data.type !== 'shared'}
                                required={data.type === 'shared'}
                            />
                            <div className="flex items-end">
                                <Switch
                                    label="Aktif"
                                    checked={data.is_active}
                                    onCheckedChange={(c) => setData('is_active', c)}
                                />
                            </div>
                        </CardContent>
                    </Card>
                    <div className="flex justify-end gap-2">
                        <Button asChild variant="outline">
                            <Link href={route('admin.bandwidth-profiles.index')}>Batal</Link>
                        </Button>
                        <Button type="submit" variant="primary" loading={processing}>
                            Simpan
                        </Button>
                    </div>
                </form>
            </div>
        </AdminLayout>
    );
}
