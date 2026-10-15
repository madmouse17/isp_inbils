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

export default function Create() {
    const { data, setData, post, processing, errors } = useForm({
        name: '',
        download_mbps: '',
        upload_mbps: '',
        type: 'shared',
        contention_ratio: '1',
        is_active: true,
    });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        post(route('admin.bandwidth-profiles.store'));
    };

    return (
        <AdminLayout title="Buat Profil Bandwidth">
            <div className="space-y-6">
                <PageHeader
                    title="Buat Profil Bandwidth"
                    subtitle="Isi data profil, lalu simpan."
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
