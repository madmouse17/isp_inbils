import { FormEvent } from 'react';
import { router, useForm } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import { Button, Card, CardContent, CardFooter, CardHeader, CardTitle, Checkbox, Input, Switch } from '@/Components/ui';

interface RoleOption { id: number; name: string }
interface CreateProps { roles: { data: RoleOption[] } }

export default function Create({ roles }: CreateProps) {
    const { data, setData, post, processing, errors } = useForm({ name: '', email: '', password: '', is_active: true, roles: [] as string[] });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        post(route('admin.users.store'));
    };

    const toggleRole = (role: string, checked: boolean) => setData('roles', checked ? [...data.roles, role] : data.roles.filter((item) => item !== role));

    return (
        <AdminLayout title="Create User">
            <form onSubmit={submit}>
                <Card>
                    <CardHeader>
                        <CardTitle>Create User</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        <div className="grid gap-4 md:grid-cols-2">
                            <Input label="Name" value={data.name} onChange={(e) => setData('name', e.target.value)} error={errors.name} required />
                            <Input label="Email" type="email" value={data.email} onChange={(e) => setData('email', e.target.value)} error={errors.email} required />
                            <Input label="Password" type="password" value={data.password} onChange={(e) => setData('password', e.target.value)} error={errors.password} required />
                            <Switch label="Active" checked={data.is_active} onCheckedChange={(checked) => setData('is_active', checked)} />
                        </div>
                        <div className="space-y-2">
                            <p className="text-sm font-medium text-surface-700 dark:text-surface-300">Roles</p>
                            <div className="grid gap-2 md:grid-cols-2">
                                {roles.data.map((role) => <Checkbox key={role.id} label={role.name} checked={data.roles.includes(role.name)} onChange={(e) => toggleRole(role.name, e.target.checked)} />)}
                            </div>
                        </div>
                    </CardContent>
                    <CardFooter className="justify-end gap-2">
                        <Button type="button" variant="secondary" onClick={() => router.get(route('admin.users.index'))}>Cancel</Button>
                        <Button type="submit" loading={processing}>Create</Button>
                    </CardFooter>
                </Card>
            </form>
        </AdminLayout>
    );
}
