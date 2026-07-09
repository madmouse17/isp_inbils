import type { FormEvent } from 'react';
import { router, useForm } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import { PageHeader } from '@/Components/composite';
import {
    Badge,
    Button,
    Card,
    CardContent,
    CardHeader,
    CardTitle,
    Checkbox,
    Input,
} from '@/Components/ui';

interface PermissionOption {
    id: number;
    name: string;
    group: string;
}
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
    const grouped = groupPermissions(permissions.data);

    const submit = (event: FormEvent) => {
        event.preventDefault();
        put(route('admin.roles.update', role.data.id));
    };

    const togglePermission = (permission: string, checked: boolean) =>
        setData(
            'permissions',
            checked
                ? [...data.permissions, permission]
                : data.permissions.filter((item) => item !== permission),
        );

    return (
        <AdminLayout title="Edit Role">
            <div className="space-y-6">
                <PageHeader title="Edit Role" subtitle="Update role permissions." />

                <form onSubmit={submit} className="space-y-6">
                    <Card>
                        <CardHeader>
                            <CardTitle>
                                Edit {role.data.name}{' '}
                                <Badge>{role.data.users_count ?? 0} users</Badge>
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="grid gap-4 md:grid-cols-2">
                            <Input
                                label="Name"
                                value={data.name}
                                onChange={(e) => setData('name', e.target.value)}
                                error={errors.name}
                                required
                            />
                            {Object.entries(grouped).map(([group, items]) => (
                                <div key={group} className="space-y-2 md:col-span-2">
                                    <p className="text-sm font-semibold capitalize text-surface-900 dark:text-surface-100">
                                        {group}
                                    </p>
                                    <div className="grid gap-2 md:grid-cols-2 lg:grid-cols-3">
                                        {items.map((permission) => (
                                            <Checkbox
                                                key={permission.id}
                                                label={permission.name}
                                                checked={data.permissions.includes(permission.name)}
                                                onChange={(e) =>
                                                    togglePermission(
                                                        permission.name,
                                                        e.target.checked,
                                                    )
                                                }
                                            />
                                        ))}
                                    </div>
                                </div>
                            ))}
                        </CardContent>
                    </Card>
                    <div className="flex justify-end gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => router.get(route('admin.roles.index'))}
                        >
                            Cancel
                        </Button>
                        <Button type="submit" loading={processing}>
                            Save
                        </Button>
                    </div>
                </form>
            </div>
        </AdminLayout>
    );
}

function groupPermissions(permissions: PermissionOption[]) {
    return permissions.reduce<Record<string, PermissionOption[]>>((groups, permission) => {
        groups[permission.group] = [...(groups[permission.group] ?? []), permission];
        return groups;
    }, {});
}
