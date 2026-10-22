import { useState, type FormEvent } from 'react';
import { Link, useForm } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import { PageHeader } from '@/Components/composite';
import {
    Button,
    Card,
    CardContent,
    CardHeader,
    CardTitle,
    Input,
    Switch,
    Textarea,
} from '@/Components/ui';
import { NativeSelect } from '@/Components/composite';
import { RoleChecklist, type RoleOption } from './components/RoleChecklist';

interface CreateProps {
    roles: RoleOption[];
    can: { createEmployee: boolean };
}

export default function Create({ roles, can }: CreateProps) {
    // A profile makes the login an assignable technician, so it is only sent when the operator turns it on.
    const [withEmployee, setWithEmployee] = useState(false);
    const { data, setData, post, processing, errors, transform } = useForm({
        name: '',
        email: '',
        is_active: true,
        roles: [] as string[],
        employee: {
            phone: '',
            hire_date: '',
            status: 'active',
            notes: '',
        },
    });

    transform((form) => ({ ...form, employee: can.createEmployee && withEmployee ? form.employee : null }));

    const submit = (event: FormEvent) => {
        event.preventDefault();
        post(route('admin.users.store'));
    };

    return (
        <AdminLayout title="Buat Pengguna">
            <div className="space-y-6">
                <PageHeader
                    title="Buat Pengguna"
                    subtitle="Akun menerima undangan lewat email untuk mengatur kata sandi. Peran menentukan hak aksesnya."
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
                    {can.createEmployee && (
                        <Card>
                            <CardHeader>
                                <CardTitle>Profil karyawan</CardTitle>
                            </CardHeader>
                            <CardContent className="grid gap-4 md:grid-cols-2">
                                <div className="md:col-span-2">
                                    <Switch
                                        label="Buat profil karyawan untuk pengguna ini"
                                        checked={withEmployee}
                                        onCheckedChange={setWithEmployee}
                                    />
                                </div>
                                {withEmployee && (
                                    <>
                                        <Input
                                            label="Telepon"
                                            value={data.employee.phone}
                                            onChange={(e) =>
                                                setData('employee', { ...data.employee, phone: e.target.value })
                                            }
                                            error={errors['employee.phone']}
                                        />
                                        <Input
                                            label="Tanggal masuk"
                                            type="date"
                                            value={data.employee.hire_date}
                                            onChange={(e) =>
                                                setData('employee', {
                                                    ...data.employee,
                                                    hire_date: e.target.value,
                                                })
                                            }
                                            error={errors['employee.hire_date']}
                                        />
                                        <NativeSelect
                                            label="Status karyawan"
                                            value={data.employee.status}
                                            onChange={(e) =>
                                                setData('employee', {
                                                    ...data.employee,
                                                    status: e.target.value,
                                                })
                                            }
                                            error={errors['employee.status']}
                                        >
                                            <option value="active">Aktif</option>
                                            <option value="inactive">Nonaktif</option>
                                        </NativeSelect>
                                        <Textarea
                                            label="Catatan"
                                            value={data.employee.notes}
                                            onChange={(e) =>
                                                setData('employee', { ...data.employee, notes: e.target.value })
                                            }
                                            error={errors['employee.notes']}
                                            className="md:col-span-2"
                                        />
                                    </>
                                )}
                            </CardContent>
                        </Card>
                    )}
                    <div className="flex justify-end gap-2">
                        <Button asChild variant="outline">
                            <Link href={route('admin.users.index')}>Batal</Link>
                        </Button>
                        <Button type="submit" variant="primary" loading={processing}>
                            Kirim undangan
                        </Button>
                    </div>
                </form>
            </div>
        </AdminLayout>
    );
}
