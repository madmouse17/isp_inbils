import type { FormEvent } from 'react';
import { Link, useForm } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import { PageHeader } from '@/Components/composite';
import { Button, Card, CardContent, CardHeader, CardTitle, Input, Switch } from '@/Components/ui';
import { RoleChecklist, type RoleOption } from './components/RoleChecklist';

interface CreateProps {
    roles: RoleOption[];
}

export default function Create({ roles }: CreateProps) {
    const { data, setData, post, processing, errors } = useForm({
        name: '',
        email: '',
        password: '',
        is_active: true,
        roles: [] as string[],
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        post(route('admin.users.store'));
    };

    return (
        <AdminLayout title="Buat Pengguna">
            <div className="space-y-6">
                <PageHeader
                    title="Buat Pengguna"
                    subtitle="Akun baru masuk dengan email dan kata sandi ini. Peran menentukan hak aksesnya."
                    actions={
                        <Button
                            asChild
                            variant="outline"
                            size="sm"
                            leftIcon={<ArrowLeft className="size-4" />}
                        >
                            <Link href={route('admin.users.index')}>Kembali</Link>
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
                                hint="Minimal 8 karakter."
                                required
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
                                />
                            </div>
                        </CardContent>
                    </Card>
                    <div className="flex justify-end gap-2">
                        <Button asChild variant="outline">
                            <Link href={route('admin.users.index')}>Batal</Link>
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
