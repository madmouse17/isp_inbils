import { useMemo } from 'react';
import AdminLayout from '@/Layouts/AdminLayout';
import { DataTable, type DataTableColumn, DynamicBadge, PageHeader } from '@/Components/composite';
import { formatGroup, formatPermission } from '@/Components/composite/permissionGroups';
import { Card, CardContent, Input } from '@/Components/ui';
import { useServerTable } from '@/hooks/useServerTable';
import { toPagination } from '@/lib/pagination';
import type { Paginated } from '@/types';

interface PermissionRow {
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
    const { params, set, setMany, sortBy, sortDir, onSort, processing } = useServerTable({
        url: route('admin.permissions.index'),
        filters,
        only: ['permissions', 'filters'],
    });

    const columns: DataTableColumn<PermissionRow>[] = useMemo(
        () => [
            {
                key: 'name',
                header: 'Kode',
                sortable: true,
                sortKey: 'name',
                cell: (permission) => <span className="font-mono text-sm">{permission.name}</span>,
            },
            {
                key: 'function',
                header: 'Fungsi',
                cell: (permission) => formatPermission(permission.name),
            },
            {
                key: 'group',
                header: 'Kelompok',
                cell: (permission) => (
                    <DynamicBadge value={permission.group}>
                        {formatGroup(permission.group)}
                    </DynamicBadge>
                ),
            },
        ],
        [],
    );

    return (
        <AdminLayout title="Hak Akses">
            <div className="space-y-6">
                <PageHeader
                    title="Hak Akses"
                    subtitle="Semua hak akses yang bisa diberikan ke peran. Daftar ini hanya untuk dibaca."
                />

                <Card>
                    <CardContent className="space-y-4 pt-6">
                        <div className="max-w-md">
                            <Input
                                label="Cari"
                                value={params.search ?? ''}
                                onChange={(event) => set('search', event.target.value)}
                                placeholder="Kode hak akses, mis. billing.view"
                            />
                        </div>
                        <DataTable
                            rowKey="name"
                            columns={columns}
                            pagination={toPagination(permissions)}
                            sortBy={sortBy}
                            sortDir={sortDir}
                            onSort={onSort}
                            onPageChange={(page) => set('page', page)}
                            onPerPageChange={(perPage) => set('per_page', perPage)}
                            emptyTitle="Belum ada hak akses"
                            emptyDescription="Hak akses dibuat bersama pembaruan sistem."
                            hasFilters={Boolean(params.search)}
                            onResetFilters={() => setMany({ search: '' })}
                            itemLabel="hak akses"
                            loading={processing}
                        />
                    </CardContent>
                </Card>
            </div>
        </AdminLayout>
    );
}
