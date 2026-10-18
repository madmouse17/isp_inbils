import { useMemo, useRef, useState } from 'react';
import { Link, router } from '@inertiajs/react';
import { Plus, Trash2 } from 'lucide-react';
import PlatformLayout from '@/Layouts/PlatformLayout';
import { DataTable, PageHeader, type DataTableColumn } from '@/Components/composite';
import { ConfirmActionDialog } from '@/Components/composite/platform/ConfirmActionDialog';
import {
    Badge,
    Button,
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
    Input,
} from '@/Components/ui';
import { useServerTable } from '@/hooks/useServerTable';
import { formatNumber } from '@/lib/format';
import { toPagination } from '@/lib/pagination';
import type { Paginated } from '@/types';
import type { PlatformRole, PlatformRoleFilters } from '@/types/platform';

type DeleteState = { role: PlatformRole } | null;

interface IndexProps {
    roles: Paginated<PlatformRole>;
    filters: PlatformRoleFilters;
    protectedRoles: string[];
}

export default function Index({ roles, filters, protectedRoles }: IndexProps) {
    const [deleting, setDeleting] = useState<DeleteState>(null);
    const [deleteProcessing, setDeleteProcessing] = useState(false);
    const [deleteErrors, setDeleteErrors] = useState<Record<string, string | undefined>>({});
    const deleteInFlight = useRef(false);
    const { params, set, setMany, sortBy, sortDir, onSort, processing } = useServerTable({
        url: route('platform.roles.index'),
        filters,
        only: ['roles', 'filters', 'protectedRoles'],
    });
    const hasFilters = Boolean(params.search);
    const createButton = (
        <Button asChild leftIcon={<Plus className="size-4" aria-hidden="true" />}>
            <Link href={route('platform.roles.create')}>Buat peran</Link>
        </Button>
    );

    const removeRole = () => {
        if (!deleting || deleteInFlight.current) return;

        deleteInFlight.current = true;
        setDeleteProcessing(true);
        setDeleteErrors({});
        router.delete(route('platform.roles.destroy', deleting.role.id), {
            preserveScroll: true,
            onError: (errors) => setDeleteErrors(errors as Record<string, string | undefined>),
            onSuccess: () => setDeleting(null),
            onFinish: () => {
                deleteInFlight.current = false;
                setDeleteProcessing(false);
            },
        });
    };

    const columns = useMemo<DataTableColumn<PlatformRole>[]>(
        () => [
            {
                key: 'name',
                header: 'Nama',
                sortable: true,
                sortKey: 'name',
                cell: (role) => (
                    <div className="flex flex-wrap items-center gap-2">
                        <Link
                            href={route('platform.roles.edit', role.id)}
                            className="font-medium text-primary-text hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-hidden"
                        >
                            {role.name}
                        </Link>
                        {protectedRoles.includes(role.name) ? (
                            <Badge variant="neutral">Bawaan</Badge>
                        ) : null}
                    </div>
                ),
            },
            {
                key: 'permissions',
                header: 'Hak akses',
                className: 'text-right tabular-nums',
                cell: (role) => formatNumber(role.permissions.length),
            },
            {
                key: 'users_count',
                header: 'Pengguna semua perusahaan',
                className: 'text-right tabular-nums',
                cell: (role) => formatNumber(role.users_count ?? 0),
            },
            {
                key: 'actions',
                header: 'Aksi',
                cell: (role) => (
                    <div className="flex items-center gap-1">
                        <Button asChild variant="outline" size="sm">
                            <Link href={route('platform.roles.edit', role.id)}>Ubah</Link>
                        </Button>
                        {!protectedRoles.includes(role.name) ? (
                            <Button
                                type="button"
                                variant="destructive-outline"
                                size="sm"
                                leftIcon={<Trash2 className="size-4" aria-hidden="true" />}
                                onClick={() => setDeleting({ role })}
                            >
                                Hapus
                            </Button>
                        ) : null}
                    </div>
                ),
            },
        ],
        [protectedRoles],
    );

    return (
        <PlatformLayout title="Peran & hak akses">
            <PageHeader
                title="Peran & hak akses"
                subtitle="Definisi peran berlaku bagi pengguna di semua perusahaan."
                actions={createButton}
            />

            <Card>
                <CardHeader>
                    <CardTitle>Peran global</CardTitle>
                    <CardDescription>
                        Perubahan hak akses langsung berlaku untuk semua pengguna yang memegang
                        peran.
                    </CardDescription>
                </CardHeader>
                <CardContent className="space-y-4">
                    <div className="max-w-md">
                        <Input
                            label="Cari"
                            placeholder="Nama peran"
                            value={params.search ?? ''}
                            onChange={(event) => set('search', event.target.value)}
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
                        emptyDescription="Buat peran untuk mengelompokkan hak akses pengguna."
                        emptyAction={createButton}
                        hasFilters={hasFilters}
                        onResetFilters={() => setMany({ search: '' })}
                        itemLabel="peran"
                        loading={processing}
                    />
                </CardContent>
            </Card>

            {deleting ? (
                <ConfirmActionDialog
                    title={`Hapus peran ${deleting.role.name}?`}
                    description={`Peran ini dipegang ${deleting.role.users_count ?? 0} pengguna. Mereka kehilangan hak akses dari peran ini setelah dihapus.`}
                    confirmLabel="Hapus peran"
                    tone="danger"
                    processing={deleteProcessing}
                    errors={deleteErrors}
                    onConfirmed={removeRole}
                    onClose={() => setDeleting(null)}
                />
            ) : null}
        </PlatformLayout>
    );
}
