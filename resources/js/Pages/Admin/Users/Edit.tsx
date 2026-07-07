import { FormEvent } from 'react';
import { router, useForm } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import { PageHeader } from '@/Components/composite';
import { Badge, Button, Card, CardContent, CardHeader, CardTitle, Checkbox, Input, Switch } from '@/Components/ui';

interface RoleOption { id: number; name: string }
interface UserData { id: number; name: string; email: string; is_active: boolean; roles: string[] }
interface EditProps { user: { data: UserData }; roles: { data: RoleOption[] } }

export default function Edit({ user, roles }: EditProps) {
    const { data, setData, put, processing, errors } = useForm({
        name: user.data.name,
        email: user.data.email,
        password: '',
        is_active: user.data.is_active,
        roles: user.data.roles ?? [],
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        put(route('admin.users.update', user.data.id));
    };

    const toggleRole = (role: string, checked: boolean) => setData('roles', checked ? [...data.roles, role] : data.roles.filter((item) => item !== role));

    return (
        <AdminLayout title="Edit User">
            <div className="space-y-6">
                <PageHeader title="Edit User" subtitle="Update user profile, status, and roles." />

                <form onSubmit={submit} className="space-y-6">
                    <Card>
                        <CardHeader>
                            <CardTitle>Edit {user.data.name} <Badge variant={data.is_active ? 'success' : 'danger'}>{data.is_active ? 'Active' : 'Inactive'}</Badge></CardTitle>
                        </CardHeader>
                        <CardContent className="grid gap-4 md:grid-cols-2">
                            <Input label="Name" value={data.name} onChange={(e) => setData('name', e.target.value)} error={errors.name} required />
                            <Input label="Email" type="email" value={data.email} onChange={(e) => setData('email', e.target.value)} error={errors.email} required />
                            <Input label="Password" type="password" value={data.password} onChange={(e) => setData('password', e.target.value)} error={errors.password} hint="Leave blank to keep current password." />
                            <Switch label="Active" checked={data.is_active} onCheckedChange={(checked) => setData('is_active', checked)} />
                            <div className="space-y-2 md:col-span-2">
                                <p className="text-sm font-medium text-surface-700 dark:text-surface-300">Roles</p>
                                <div className="grid gap-2 md:grid-cols-2">
                                    {roles.data.map((role) => <Checkbox key={role.id} label={role.name} checked={data.roles.includes(role.name)} onChange={(e) => toggleRole(role.name, e.target.checked)} />)}
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                    <div className="flex justify-end gap-2">
                        <Button type="button" variant="outline" onClick={() => router.get(route('admin.users.index'))}>Cancel</Button>
                        <Button type="submit" loading={processing}>Save</Button>
                    </div>
                </form>
            </div>
        </AdminLayout>
    );
}
