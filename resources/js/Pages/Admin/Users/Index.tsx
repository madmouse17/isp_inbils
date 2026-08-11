import { useMemo } from 'react';
import { router } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import {
    DataTable,
    DataTableActions,
    type DataTableColumn,
    PageHeader,
    RoleBadge,
} from '@/Components/composite';
import { ExportMenu } from '@/Components/ExportMenu';
import { Badge, Button, Card, CardContent, Input, NativeSelect } from '@/Components/ui';
import { useServerTable } from '@/hooks/useServerTable';
import { toPagination } from '@/lib/pagination';
import type { Paginated } from '@/types';

interface UserRow {
    id: number;
    name: string;
    email: string;
    roles: string[];
    is_active: boolean;
}

interface UsersProps {
    users: Paginated<UserRow>;
    filters: {
        search?: string;
        is_active?: string;
        sort?: string;
        direction?: string;
        per_page?: string | number;
    };
    can: { create: boolean; export: boolean };
}

export default function Index({ users, filters, can }: UsersProps) {
    const { params, set, sortBy, sortDir, onSort, processing } = useServerTable({
        url: route('admin.users.index'),
        filters,
        only: ['users', 'filters', 'can'],
    });

    const columns: DataTableColumn<UserRow>[] = useMemo(
        () => [
            {
                key: 'name',
                header: 'Name',
                sortable: true,
                sortKey: 'name',
            },
            {
                key: 'email',
                header: 'Email',
                sortable: true,
                sortKey: 'email',
            },
            {
                key: 'roles',
                header: 'Roles',
                cell: (user) => (
                    <div className="flex flex-wrap gap-1">
                        {user.roles.map((role) => (
                            <RoleBadge key={role} role={role} />
                        ))}
                    </div>
                ),
            },
            {
                key: 'is_active',
                header: 'Status',
                sortable: true,
                sortKey: 'is_active',
                cell: (user) => (
                    <Badge variant={user.is_active ? 'success' : 'danger'}>
                        {user.is_active ? 'Active' : 'Inactive'}
                    </Badge>
                ),
            },
            {
                key: 'actions',
                header: 'Actions',
                cell: (user) => (
                    <DataTableActions
                        showHref={route('admin.users.show', user.id)}
                        editHref={route('admin.users.edit', user.id)}
                        deleteHref={route('admin.users.destroy', user.id)}
                        deleteMessage={`Delete ${user.name}?`}
                    />
                ),
            },
        ],
        [],
    );

    return (
        <AdminLayout title="Users">
            <div className="space-y-6">
                <PageHeader
                    title="Users"
                    subtitle="Manage company users and roles."
                    actions={
                        <div className="flex flex-wrap gap-2">
                            {can.export ? (
                                <ExportMenu
                                    exportUrl={route('admin.users.export')}
                                    params={params}
                                    formats={['csv', 'pdf']}
                                    canExport={can.export}
                                />
                            ) : null}
                            {can.create ? (
                                <Button
                                    type="button"
                                    variant="success"
                                    onClick={() => router.get(route('admin.users.create'))}
                                >
                                    Create User
                                </Button>
                            ) : null}
                        </div>
                    }
                />

                <Card>
                    <CardContent className="space-y-4 pt-6">
                        <div className="flex flex-col gap-3 rounded-lg border bg-card p-3 md:flex-row md:items-end">
                            <div className="min-w-[12rem] flex-1">
                                <Input
                                    label="Search"
                                    value={params.search ?? ''}
                                    onChange={(event) => set('search', event.target.value)}
                                    placeholder="Name or email"
                                />
                            </div>
                            <div className="w-full md:w-40">
                                <NativeSelect
                                    label="Status"
                                    value={params.is_active ?? ''}
                                    onChange={(event) => set('is_active', event.target.value)}
                                    options={[
                                        { value: '', label: 'All statuses' },
                                        { value: '1', label: 'Active' },
                                        { value: '0', label: 'Inactive' },
                                    ]}
                                />
                            </div>
                        </div>

                        <DataTable
                            columns={columns}
                            pagination={toPagination(users)}
                            sortBy={sortBy}
                            sortDir={sortDir}
                            onSort={onSort}
                            onPageChange={(page) => set('page', page)}
                            onPerPageChange={(perPage) => set('per_page', perPage)}
                            emptyTitle="No users"
                            emptyDescription="No users match the current filters."
                            loading={processing}
                        />
                    </CardContent>
                </Card>
            </div>
        </AdminLayout>
    );
}
