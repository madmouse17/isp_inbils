import { useMemo } from 'react';
import { Link } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import { Button, Card, CardContent, EmptyCell, Input } from '@/Components/ui';
import {
    DataTable,
    DataTableActions,
    PageHeader,
    type DataTableColumn,
} from '@/Components/composite';
import { NasStatusBadge } from '@/Components/composite/NetworkAccessBadges';
import { useTableQuery } from '@/hooks/useTableQuery';
import { toPagination, type PaginationLike } from '@/lib/pagination';
import type { NasDevice } from '@/types/network-asset';

interface IndexProps extends Record<string, unknown> {
    nasDevices: PaginationLike<NasDevice>;
    filters: {
        search?: string | null;
        sort_by?: string | null;
        sort_dir?: string | null;
        per_page?: string | number | null;
    };
    can: { create: boolean };
}

export default function Index({ nasDevices, filters, can }: IndexProps) {
    const pagination = toPagination(nasDevices);
    const { params, set, sortBy, sortDir, onSort, processing } = useTableQuery(
        {
            search: filters.search ?? '',
            sort_by: filters.sort_by ?? '',
            sort_dir: filters.sort_dir ?? '',
            per_page: filters.per_page ?? pagination.per_page,
        },
        { only: ['nasDevices', 'filters'] },
    );

    const columns: DataTableColumn<NasDevice>[] = useMemo(
        () => [
            {
                key: 'code',
                header: 'Router',
                sortable: true,
                sortKey: 'code',
                cell: (row) => (
                    <div className="space-y-0.5">
                        <Link
                            href={route('admin.nas-devices.show', row.id)}
                            className="font-medium text-primary hover:underline"
                        >
                            {row.network_asset?.code ?? `Router #${row.id}`}
                        </Link>
                        {row.network_asset?.name ? (
                            <p className="text-xs text-muted-foreground">
                                {row.network_asset.name}
                            </p>
                        ) : null}
                    </div>
                ),
            },
            {
                key: 'location',
                header: 'Lokasi',
                cell: (row) => row.network_asset?.location?.name ?? <EmptyCell />,
            },
            {
                key: 'nasname',
                header: 'Alamat NAS',
                cell: (row) => <span className="font-mono text-sm">{row.nasname}</span>,
            },
            {
                key: 'coa_port',
                header: 'Port CoA',
                cell: (row) => row.coa_port,
            },
            {
                key: 'status',
                header: 'RADIUS',
                cell: (row) => <NasStatusBadge nas={row} />,
            },
            {
                key: 'actions',
                header: 'Aksi',
                className: 'text-right',
                cell: (row) => (
                    <DataTableActions
                        showHref={route('admin.nas-devices.show', row.id)}
                        editHref={can.create ? route('admin.nas-devices.edit', row.id) : undefined}
                    />
                ),
            },
        ],
        [can.create],
    );

    const registerLink = (
        <Button asChild size="sm">
            <Link href={route('admin.nas-devices.create')}>Daftarkan router</Link>
        </Button>
    );

    return (
        <AdminLayout title="Router NAS">
            <div className="space-y-6">
                <PageHeader
                    title="Router NAS"
                    subtitle="Router yang memakai FreeRADIUS untuk mengautentikasi pelanggan."
                    actions={can.create ? registerLink : undefined}
                />

                <Card>
                    <CardContent className="pt-6">
                        <div className="max-w-md">
                            <Input
                                id="nas-search"
                                label="Cari"
                                value={params.search ?? ''}
                                onChange={(event) => set('search', event.target.value)}
                                placeholder="Kode, nama, atau alamat NAS"
                            />
                        </div>
                    </CardContent>
                </Card>

                <DataTable
                    columns={columns}
                    data={nasDevices.data}
                    emptyTitle="Belum ada router NAS"
                    emptyDescription="Daftarkan router sebagai klien RADIUS agar FreeRADIUS menerima permintaannya."
                    emptyAction={can.create ? registerLink : undefined}
                    hasFilters={Boolean(params.search)}
                    onResetFilters={() => set('search', '')}
                    itemLabel="router NAS"
                    loading={processing}
                    sortBy={sortBy}
                    sortDir={sortDir}
                    onSort={onSort}
                    pagination={pagination}
                    onPageChange={(page) => set('page', page)}
                    onPerPageChange={(perPage) => set('per_page', perPage)}
                />
            </div>
        </AdminLayout>
    );
}
