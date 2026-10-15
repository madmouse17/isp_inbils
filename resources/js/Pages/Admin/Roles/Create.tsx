import type { FormEvent } from 'react';
import { Link, useForm } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import { PageHeader, PermissionGroupSelector, type PermissionOption } from '@/Components/composite';
import { Button, Card, CardContent, CardHeader, CardTitle, Input } from '@/Components/ui';
interface CreateProps {
    permissions: { data: PermissionOption[] };
}

export default function Create({ permissions }: CreateProps) {
    const { data, setData, post, processing, errors } = useForm({
        name: '',
        permissions: [] as string[],
    });
    const submit = (event: FormEvent) => {
        event.preventDefault();
        post(route('admin.roles.store'));
    };

    return (
        <AdminLayout title="Buat Peran">
            <div className="space-y-6">
                <PageHeader
                    title="Buat Peran"
                    subtitle="Beri nama peran, lalu pilih hak akses yang dimilikinya."
                    actions={
                        <Button
                            asChild
                            variant="outline"
                            size="sm"
                            leftIcon={<ArrowLeft className="size-4" />}
                        >
                            <Link href={route('admin.roles.index')}>Kembali</Link>
                        </Button>
                    }
                />

                <form onSubmit={submit} className="space-y-6">
                    <Card>
                        <CardHeader>
                            <CardTitle>Data peran</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-6">
                            <div className="max-w-xl">
                                <Input
                                    label="Nama"
                                    value={data.name}
                                    onChange={(e) => setData('name', e.target.value)}
                                    error={errors.name}
                                    required
                                />
                            </div>
                            <PermissionGroupSelector
                                permissions={permissions.data}
                                value={data.permissions}
                                onChange={(selected) => setData('permissions', selected)}
                                error={errors.permissions}
                            />
                        </CardContent>
                    </Card>
                    <div className="flex justify-end gap-2">
                        <Button asChild variant="outline">
                            <Link href={route('admin.roles.index')}>Batal</Link>
                        </Button>
                        <Button type="submit" variant="primary" loading={processing}>
                            Buat
                        </Button>
                    </div>
                </form>
            </div>
        </AdminLayout>
    );
}
