import { useMemo } from 'react';
import AdminLayout from '@/Layouts/AdminLayout';
import {
    DataTable,
    type DataTableColumn,
    DynamicBadge,
    PageHeader,
} from '@/Components/composite';
import { Card, CardContent, Input } from '@/Components/ui';
import { useServerTable } from '@/hooks/useServerTable';
import { toPagination } from '@/lib/pagination';
import type { Paginated } from '@/types';

interface PermissionRow {
    id: number;
    name: string;
    group: string;
}

interface PermissionsProps {
    permissions: Paginated<PermissionRow>;
    filters: {
        search?: string;
        sort?: string;
        direction?: string;
        per_page?: string | number;
    };
}

export default function Index({ permissions, filters }: PermissionsProps) {
    const { params, set, sortBy, sortDir, onSort, processing } = useServerTable({
        url: route('admin.permissions.index'),
        filters,
        only: ['permissions', 'filters'],
    });

    const columns: DataTableColumn<PermissionRow>[] = useMemo(
        () => [
            {
                key: 'name',
                header: 'Name',
                sortable: true,
                sortKey: 'name',
                cell: (permission) => (
                    <span className="font-medium">{permission.name}</span>
                ),
            },
            {
                key: 'group',
                header: 'Group',
                cell: (permission) => <DynamicBadge value={permission.group} />,
            },
        ],
        [],
    );

    return (
        <AdminLayout title="Permissions">
            <div className="space-y-6">
                <PageHeader title="Permissions" subtitle="Read-only permission matrix." />

                <Card>
                    <CardContent className="space-y-4 pt-6">
                        <Input
                            label="Search"
                            value={params.search ?? ''}
                            onChange={(event) => set('search', event.target.value)}
                            placeholder="Permission name"
                        />
                        <DataTable
                            columns={columns}
                            pagination={toPagination(permissions)}
                            sortBy={sortBy}
                            sortDir={sortDir}
                            onSort={onSort}
                            onPageChange={(page) => set('page', page)}
                            onPerPageChange={(perPage) => set('per_page', perPage)}
                            loading={processing}
                        />
                    </CardContent>
                </Card>
            </div>
        </AdminLayout>
    );
}
