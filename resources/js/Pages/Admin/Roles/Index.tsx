import { useMemo } from 'react';
import { router } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import {
    DataTable,
    DataTableActions,
    type DataTableColumn,
    PageHeader,
} from '@/Components/composite';
import { ExportMenu } from '@/Components/ExportMenu';
import { Badge, Button, Card, CardContent, Input } from '@/Components/ui';
import { useServerTable } from '@/hooks/useServerTable';
import { toPagination } from '@/lib/pagination';
import type { Paginated } from '@/types';

interface RoleRow {
    id: number;
    name: string;
    permissions: string[];
    users_count?: number;
}

interface RolesProps {
    roles: Paginated<RoleRow>;
    filters: {
        search?: string;
        sort?: string;
        direction?: string;
        per_page?: string | number;
    };
    can: { create: boolean; export: boolean };
}

const protectedRoles = new Set(['admin', 'manager', 'staff', 'technician', 'customer']);

export default function Index({ roles, filters, can }: RolesProps) {
    const { params, set, sortBy, sortDir, onSort, processing } = useServerTable({
        url: route('admin.roles.index'),
        filters,
        only: ['roles', 'filters', 'can'],
    });

    const columns: DataTableColumn<RoleRow>[] = useMemo(
        () => [
            {
                key: 'name',
                header: 'Name',
                sortable: true,
                sortKey: 'name',
                cell: (role) => (
                    <div className="flex items-center gap-2">
                        <span className="font-medium">{role.name}</span>
                        {protectedRoles.has(role.name) ? (
                            <Badge variant="warning">Protected</Badge>
                        ) : null}
                    </div>
                ),
            },
            {
                key: 'permissions',
                header: 'Permissions',
                cell: (role) => <Badge variant="info">{role.permissions.length}</Badge>,
            },
            {
                key: 'users_count',
                header: 'Users',
                cell: (role) => <Badge variant="secondary">{role.users_count ?? 0}</Badge>,
            },
            {
                key: 'actions',
                header: 'Actions',
                cell: (role) => (
                    <DataTableActions
                        editHref={route('admin.roles.edit', role.id)}
                        deleteHref={
                            protectedRoles.has(role.name)
                                ? undefined
                                : route('admin.roles.destroy', role.id)
                        }
                        deleteMessage={`Delete ${role.name}?`}
                    />
                ),
            },
        ],
        [],
    );

    return (
        <AdminLayout title="Roles">
            <div className="space-y-6">
                <PageHeader
                    title="Roles"
                    subtitle="Manage RBAC roles and permission sets."
                    actions={
                        <div className="flex flex-wrap gap-2">
                            {can.export ? (
                                <ExportMenu
                                    exportUrl={route('admin.roles.export')}
                                    params={params}
                                    formats={['csv', 'pdf']}
                                    canExport={can.export}
                                />
                            ) : null}
                            {can.create ? (
                                <Button
                                    type="button"
                                    variant="success"
                                    onClick={() => router.get(route('admin.roles.create'))}
                                >
                                    Create Role
                                </Button>
                            ) : null}
                        </div>
                    }
                />

                <Card>
                    <CardContent className="space-y-4 pt-6">
                        <Input
                            label="Search"
                            value={params.search ?? ''}
                            onChange={(event) => set('search', event.target.value)}
                            placeholder="Role name"
                        />
                        <DataTable
                            columns={columns}
                            pagination={toPagination(roles)}
                            sortBy={sortBy}
                            sortDir={sortDir}
                            onSort={onSort}
                            onPageChange={(page) => set('page', page)}
                            onPerPageChange={(perPage) => set('per_page', perPage)}
                            emptyTitle="No roles"
                            emptyDescription="No roles match the current search."
                            loading={processing}
                        />
                    </CardContent>
                </Card>
            </div>
        </AdminLayout>
    );
}
