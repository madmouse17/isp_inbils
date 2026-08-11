import type { FormEvent } from 'react';
import { router, useForm } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import { PageHeader, PermissionGroupSelector, type PermissionOption } from '@/Components/composite';
import { Badge, Button, Card, CardContent, CardHeader, CardTitle, Input } from '@/Components/ui';
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
                        <CardContent className="space-y-6">
                            <div className="max-w-xl">
                                <Input
                                    label="Name"
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
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => router.get(route('admin.roles.index'))}
                        >
                            Cancel
                        </Button>
                        <Button type="submit" variant="success" loading={processing}>
                            Save
                        </Button>
                    </div>
                </form>
            </div>
        </AdminLayout>
    );
}
