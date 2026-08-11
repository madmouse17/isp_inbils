import type { FormEvent } from 'react';
import { router, useForm } from '@inertiajs/react';
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
        <AdminLayout title="Create Role">
            <div className="space-y-6">
                <PageHeader title="Create Role" subtitle="Fill required fields, then save." />

                <form onSubmit={submit} className="space-y-6">
                    <Card>
                        <CardHeader>
                            <CardTitle>Create Role</CardTitle>
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
                            Create
                        </Button>
                    </div>
                </form>
            </div>
        </AdminLayout>
    );
}
