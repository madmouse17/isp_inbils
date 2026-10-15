import type { FormEvent } from 'react';
import { Link, useForm } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import { PageHeader, PermissionGroupSelector, type PermissionOption } from '@/Components/composite';
import { Button, Card, CardContent, CardHeader, CardTitle, Input } from '@/Components/ui';
interface RoleData {
    id: number;
    name: string;
    permissions: string[];
    users_count?: number;
}
interface EditProps {
    role: { data: RoleData };
    permissions: { data: PermissionOption[] };
}

export default function Edit({ role, permissions }: EditProps) {
    const { data, setData, put, processing, errors } = useForm({
        name: role.data.name,
        permissions: role.data.permissions ?? [],
    });
    const submit = (event: FormEvent) => {
        event.preventDefault();
        put(route('admin.roles.update', role.data.id));
    };

    return (
        <AdminLayout title="Ubah Peran">
            <div className="space-y-6">
                <PageHeader
                    title="Ubah Peran"
                    subtitle={`Peran ${role.data.name} dipegang ${role.data.users_count ?? 0} pengguna di perusahaan ini. Perubahan berlaku untuk semua pengguna peran ini, di semua perusahaan.`}
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
                            Simpan
                        </Button>
                    </div>
                </form>
            </div>
        </AdminLayout>
    );
}
