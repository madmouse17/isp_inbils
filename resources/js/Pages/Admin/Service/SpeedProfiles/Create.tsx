import type { FormEvent } from 'react';
import { Link, useForm } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import { Button, Card, CardContent, CardHeader, CardTitle, Input, Switch } from '@/Components/ui';
import { PageHeader } from '@/Components/composite';

export default function Create() {
    const { data, setData, post, processing, errors } = useForm({
        name: '',
        download_max_mbps: '',
        upload_max_mbps: '',
        burst_download_mbps: '',
        burst_upload_mbps: '',
        radius_profile_name: '',
        is_active: true,
    });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        post(route('admin.speed-profiles.store'));
    };

    return (
        <AdminLayout title="Buat Profil Kecepatan">
            <div className="space-y-6">
                <PageHeader
                    title="Buat Profil Kecepatan"
                    subtitle="Isi data profil, lalu simpan."
                    actions={
                        <Button
                            asChild
                            variant="outline"
                            size="sm"
                            leftIcon={<ArrowLeft className="size-4" />}
                        >
                            <Link href={route('admin.speed-profiles.index')}>Kembali</Link>
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
                            <Input
                                label="Unduh maksimum (Mbps)"
                                type="number"
                                value={data.download_max_mbps}
                                onChange={(e) => setData('download_max_mbps', e.target.value)}
                                error={errors.download_max_mbps}
                                required
                            />
                            <Input
                                label="Unggah maksimum (Mbps)"
                                type="number"
                                value={data.upload_max_mbps}
                                onChange={(e) => setData('upload_max_mbps', e.target.value)}
                                error={errors.upload_max_mbps}
                                required
                            />
                            <Input
                                label="Burst unduh (Mbps)"
                                type="number"
                                value={data.burst_download_mbps}
                                onChange={(e) => setData('burst_download_mbps', e.target.value)}
                                error={errors.burst_download_mbps}
                            />
                            <Input
                                label="Burst unggah (Mbps)"
                                type="number"
                                value={data.burst_upload_mbps}
                                onChange={(e) => setData('burst_upload_mbps', e.target.value)}
                                error={errors.burst_upload_mbps}
                            />
                            <Input
                                label="Nama profil RADIUS"
                                value={data.radius_profile_name}
                                onChange={(e) => setData('radius_profile_name', e.target.value)}
                                error={errors.radius_profile_name}
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
                            <Link href={route('admin.speed-profiles.index')}>Batal</Link>
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
