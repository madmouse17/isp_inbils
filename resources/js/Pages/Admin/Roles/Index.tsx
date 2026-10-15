import { useMemo } from 'react';
import { Link } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import {
    DataTable,
    DataTableActions,
    type DataTableColumn,
    PageHeader,
} from '@/Components/composite';
import { ExportMenu } from '@/Components/ui/ExportMenu';
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

/** The seeded roles (`RolePolicy`): they cannot be deleted. */
const PROTECTED_ROLES: Record<string, true> = {
    admin: true,
    manager: true,
    staff: true,
    technician: true,
    customer: true,
};

export default function Index({ roles, filters, can }: RolesProps) {
    const { params, set, setMany, sortBy, sortDir, onSort, processing } = useServerTable({
        url: route('admin.roles.index'),
        filters,
        only: ['roles', 'filters', 'can'],
    });

    const hasFilters = Boolean(params.search);

    const columns: DataTableColumn<RoleRow>[] = useMemo(
        () => [
            {
                key: 'name',
                header: 'Nama',
                sortable: true,
                sortKey: 'name',
                cell: (role) => (
                    <div className="flex items-center gap-2">
                        <span className="font-medium">{role.name}</span>
                        {Object.hasOwn(PROTECTED_ROLES, role.name) ? (
                            <Badge variant="neutral">Bawaan</Badge>
                        ) : null}
                    </div>
                ),
            },
            {
                key: 'permissions',
                header: 'Hak akses',
                cell: (role) => role.permissions.length,
            },
            {
                key: 'users_count',
                header: 'Pengguna',
                cell: (role) => role.users_count ?? 0,
            },
            {
                key: 'actions',
                header: 'Aksi',
                cell: (role) => (
                    <DataTableActions
                        editHref={route('admin.roles.edit', role.id)}
                        deleteHref={
                            Object.hasOwn(PROTECTED_ROLES, role.name)
                                ? undefined
                                : route('admin.roles.destroy', role.id)
                        }
                        deleteMessage={`Hapus peran ${role.name}?`}
                        deleteDescription={`Peran "${role.name}" akan dihapus dan dicabut dari semua penggunanya.`}
                    />
                ),
            },
        ],
        [],
    );

    const createButton = can.create ? (
        <Button asChild variant="primary">
            <Link href={route('admin.roles.create')}>Buat Peran</Link>
        </Button>
    ) : undefined;

    return (
        <AdminLayout title="Peran">
            <div className="space-y-6">
                <PageHeader
                    title="Peran"
                    subtitle="Kumpulan hak akses yang diberikan ke pengguna lewat peran."
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
                            {createButton}
                        </div>
                    }
                />

                <Card>
                    <CardContent className="space-y-4 pt-6">
                        <div className="max-w-md">
                            <Input
                                label="Cari"
                                value={params.search ?? ''}
                                onChange={(event) => set('search', event.target.value)}
                                placeholder="Nama peran"
                            />
                        </div>
                        <DataTable
                            columns={columns}
                            pagination={toPagination(roles)}
                            sortBy={sortBy}
                            sortDir={sortDir}
                            onSort={onSort}
                            onPageChange={(page) => set('page', page)}
                            onPerPageChange={(perPage) => set('per_page', perPage)}
                            emptyTitle="Belum ada peran"
                            emptyDescription="Peran mengelompokkan hak akses supaya bisa diberikan sekaligus ke pengguna. Buat peran pertama untuk memulai."
                            emptyAction={createButton}
                            hasFilters={hasFilters}
                            onResetFilters={() => setMany({ search: '' })}
                            itemLabel="peran"
                            loading={processing}
                        />
                    </CardContent>
                </Card>
            </div>
        </AdminLayout>
    );
}
