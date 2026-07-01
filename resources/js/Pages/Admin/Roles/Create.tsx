import { FormEvent } from 'react';
import { router, useForm } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import { Button, Card, CardContent, CardFooter, CardHeader, CardTitle, Checkbox, Input } from '@/Components/ui';

interface PermissionOption { id: number; name: string; group: string }
interface CreateProps { permissions: { data: PermissionOption[] } }

export default function Create({ permissions }: CreateProps) {
    const { data, setData, post, processing, errors } = useForm({ name: '', permissions: [] as string[] });
    const grouped = groupPermissions(permissions.data);

    const submit = (event: FormEvent) => {
        event.preventDefault();
        post(route('admin.roles.store'));
    };

    const togglePermission = (permission: string, checked: boolean) => setData('permissions', checked ? [...data.permissions, permission] : data.permissions.filter((item) => item !== permission));

    return (
        <AdminLayout title="Create Role">
            <form onSubmit={submit}>
                <Card>
                    <CardHeader><CardTitle>Create Role</CardTitle></CardHeader>
                    <CardContent className="space-y-6">
                        <Input label="Name" value={data.name} onChange={(e) => setData('name', e.target.value)} error={errors.name} required />
                        {Object.entries(grouped).map(([group, items]) => (
                            <div key={group} className="space-y-2">
                                <p className="text-sm font-semibold capitalize text-surface-900 dark:text-surface-100">{group}</p>
                                <div className="grid gap-2 md:grid-cols-2 lg:grid-cols-3">
                                    {items.map((permission) => <Checkbox key={permission.id} label={permission.name} checked={data.permissions.includes(permission.name)} onChange={(e) => togglePermission(permission.name, e.target.checked)} />)}
                                </div>
                            </div>
                        ))}
                    </CardContent>
                    <CardFooter className="justify-end gap-2">
                        <Button type="button" variant="secondary" onClick={() => router.get(route('admin.roles.index'))}>Cancel</Button>
                        <Button type="submit" loading={processing}>Create</Button>
                    </CardFooter>
                </Card>
            </form>
        </AdminLayout>
    );
}

function groupPermissions(permissions: PermissionOption[]) {
    return permissions.reduce<Record<string, PermissionOption[]>>((groups, permission) => {
        groups[permission.group] = [...(groups[permission.group] ?? []), permission];
        return groups;
    }, {});
}
