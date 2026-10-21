import { useMemo, useState } from 'react';
import { Link } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import { DataTable, type DataTableColumn, PageHeader } from '@/Components/composite';
import { ExportMenu } from '@/Components/ui/ExportMenu';
import { Badge, Button, Card, CardContent, Input } from '@/Components/ui';
import { useServerTable } from '@/hooks/useServerTable';
import { toPagination } from '@/lib/pagination';
import type { Paginated } from '@/types';
import { DeleteRoleDialog } from './components/DeleteRoleDialog';

interface RoleRow {
    id: string;
    name: string;
    template_key: string | null;
    permissions_count?: number;
    users_count?: number;
    can: { update: boolean; delete: boolean };
}

interface RolesProps {
    roles: Paginated<RoleRow>;
    filters: {
        search?: string;
        sort?: string;
        direction?: string;
        per_page?: string | number;
    };
    moveTargets: { id: string; name: string }[];
    templateNames: Record<string, string>;
    can: { create: boolean; export: boolean };
}

export default function Index({ roles, filters, moveTargets, templateNames, can }: RolesProps) {
    const { params, set, setMany, sortBy, sortDir, onSort, processing } = useServerTable({
        url: route('admin.roles.index'),
        filters,
        only: ['roles', 'filters', 'can'],
    });
    const [deleting, setDeleting] = useState<RoleRow | null>(null);

    const hasFilters = Boolean(params.search);

    const createButton = can.create ? (
        <Button asChild variant="primary">
            <Link href={route('admin.roles.create')}>Buat peran</Link>
        </Button>
    ) : undefined;

    const columns: DataTableColumn<RoleRow>[] = useMemo(
        () => [
            {
                key: 'name',
                header: 'Nama',
                sortable: true,
                sortKey: 'name',
                cell: (role) => (
                    <Link
                        href={route('admin.roles.show', role.id)}
                        className="font-medium text-primary-text hover:underline"
                    >
                        {role.name}
                    </Link>
                ),
            },
            {
                key: 'template_key',
                header: 'Templat',
                cell: (role) =>
                    role.template_key ? (
                        <Badge variant="neutral">
                            Dari templat {templateNames[role.template_key] ?? role.template_key}
                        </Badge>
                    ) : (
                        <span className="text-muted-foreground">Dibuat sendiri</span>
                    ),
            },
            {
                key: 'permissions_count',
                header: 'Hak akses',
                cell: (role) => role.permissions_count ?? 0,
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
                    <div className="flex items-center gap-1">
                        <Button asChild variant="outline" size="sm">
                            <Link
                                href={
                                    role.can.update
                                        ? route('admin.roles.edit', role.id)
                                        : route('admin.roles.show', role.id)
                                }
                                aria-label={`${role.can.update ? 'Ubah' : 'Lihat'} peran ${role.name}`}
                            >
                                {role.can.update ? 'Ubah' : 'Lihat'}
                            </Link>
                        </Button>
                        {role.can.delete ? (
                            <Button
                                type="button"
                                variant="destructive-outline"
                                size="sm"
                                aria-label={`Hapus peran ${role.name}`}
                                onClick={() => setDeleting(role)}
                            >
                                Hapus
                            </Button>
                        ) : null}
                    </div>
                ),
            },
        ],
        [templateNames],
    );

    return (
        <AdminLayout title="Peran">
            <div className="space-y-6">
                <PageHeader
                    title="Peran"
                    subtitle="Kumpulan hak akses yang diberikan ke pengguna lewat peran. Peran ini hanya berlaku di perusahaan ini."
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
                            emptyDescription="Peran mengelompokkan hak akses supaya bisa diberikan sekaligus ke pengguna. Buat peran pertama, lalu berikan ke pengguna di menu Pengguna."
                            emptyAction={createButton}
                            hasFilters={hasFilters}
                            onResetFilters={() => setMany({ search: '' })}
                            itemLabel="peran"
                            loading={processing}
                        />
                    </CardContent>
                </Card>
            </div>

            {deleting ? (
                <DeleteRoleDialog
                    key={deleting.id}
                    role={deleting}
                    moveTargets={moveTargets}
                    onClose={() => setDeleting(null)}
                />
            ) : null}
        </AdminLayout>
    );
}
