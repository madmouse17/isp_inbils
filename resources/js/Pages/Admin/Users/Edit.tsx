import type { FormEvent } from 'react';
import { Link, useForm } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import { PageHeader } from '@/Components/composite';
import { Button, Card, CardContent, CardHeader, CardTitle, Input, Switch } from '@/Components/ui';
import { RoleChecklist, splitHeldRoles, type RoleOption } from './components/RoleChecklist';

interface UserData {
    id: string;
    name: string;
    email: string;
    is_active: boolean;
    roles: string[];
}
interface EditProps {
    user: { data: UserData };
    roles: RoleOption[];
}

export default function Edit({ user, roles }: EditProps) {
    // A role this list does not offer (a customer account's) is not sent: the server keeps it.
    const { inside, outside } = splitHeldRoles(user.data.roles ?? [], roles);
    const { data, setData, put, processing, errors } = useForm({
        name: user.data.name,
        email: user.data.email,
        password: '',
        is_active: user.data.is_active,
        roles: inside,
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        put(route('admin.users.update', user.data.id));
    };

    return (
        <AdminLayout title="Ubah Pengguna">
            <div className="space-y-6">
                <PageHeader
                    title="Ubah Pengguna"
                    subtitle={`${user.data.name} (${user.data.email})`}
                    actions={
                        <Button
                            asChild
                            variant="outline"
                            size="sm"
                            leftIcon={<ArrowLeft className="size-4" />}
                        >
                            <Link href={route('admin.users.show', user.data.id)}>Kembali</Link>
                        </Button>
                    }
                />

                <form onSubmit={submit} className="space-y-6">
                    <Card>
                        <CardHeader>
                            <CardTitle>Data akun</CardTitle>
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
                                label="Email"
                                type="email"
                                value={data.email}
                                onChange={(e) => setData('email', e.target.value)}
                                error={errors.email}
                                required
                            />
                            <Input
                                label="Kata sandi"
                                type="password"
                                autoComplete="new-password"
                                value={data.password}
                                onChange={(e) => setData('password', e.target.value)}
                                error={errors.password}
                                hint="Kosongkan untuk mempertahankan kata sandi saat ini."
                            />
                            <Switch
                                label="Aktif"
                                checked={data.is_active}
                                onCheckedChange={(checked) => setData('is_active', checked)}
                            />
                            <div className="md:col-span-2">
                                <RoleChecklist
                                    options={roles}
                                    value={data.roles}
                                    onChange={(selected) => setData('roles', selected)}
                                    error={errors.roles}
                                    outside={outside}
                                />
                            </div>
                        </CardContent>
                    </Card>
                    <div className="flex justify-end gap-2">
                        <Button asChild variant="outline">
                            <Link href={route('admin.users.index')}>Batal</Link>
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
