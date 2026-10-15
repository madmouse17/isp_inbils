import { Link } from '@inertiajs/react';
import { useMemo } from 'react';
import { Plus } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import { PageHeader } from '@/Components/composite/PageHeader';
import { DataTable, type DataTableColumn } from '@/Components/composite/DataTable';
import { DataTableActions } from '@/Components/composite/DataTableActions';
import { Button, EmptyCell, EnumBadge, ExportMenu, Input, NativeSelect } from '@/Components/ui';
import { useCan } from '@/hooks/useCan';
import { useTableQuery } from '@/hooks/useTableQuery';
import { formatDate } from '@/lib/format';
import { toPagination, type PaginationLike } from '@/lib/pagination';
import { NETWORK_ASSET_STATUS, NETWORK_ASSET_TYPE, optionsOf } from '@/lib/statuses';
import type { NetworkAssetStatus, NetworkAssetType } from '@/types/network-asset';

type AssetRow = {
    id: number;
    code: string;
    name: string;
    asset_type: NetworkAssetType;
    status: NetworkAssetStatus;
    serial_number?: string | null;
    created_at: string | null;
    location?: { id: number; name: string } | null;
};

type Props = {
    assets: PaginationLike<AssetRow>;
    filters: {
        search?: string;
        asset_type?: string;
        status?: string;
        sort_by?: string;
        sort_dir?: string;
        per_page?: string | number;
    };
};

const typeFilterOptions = [{ value: '', label: 'Semua tipe' }, ...optionsOf(NETWORK_ASSET_TYPE)];
const statusFilterOptions = [
    { value: '', label: 'Semua status' },
    ...optionsOf(NETWORK_ASSET_STATUS),
];

export default function NetworkAssetsIndex({ assets, filters }: Props) {
    const can = useCan();
    const pagination = toPagination(assets);
    const { params, set, setMany, sortBy, sortDir, onSort, processing } = useTableQuery(
        {
            search: filters.search ?? '',
            asset_type: filters.asset_type ?? '',
            status: filters.status ?? '',
            sort_by: filters.sort_by ?? '',
            sort_dir: filters.sort_dir ?? '',
            per_page: filters.per_page ?? pagination.per_page,
        },
        { only: ['assets', 'filters'] },
    );
    const hasFilters = Boolean(params.search || params.asset_type || params.status);

    const columns: DataTableColumn<AssetRow>[] = useMemo(
        () => [
            {
                key: 'code',
                header: 'Kode',
                sortable: true,
                sortKey: 'code',
                cell: (row) => (
                    <Link
                        href={route('admin.network-assets.show', row.id)}
                        className="font-medium text-primary hover:underline"
                    >
                        {row.code}
                    </Link>
                ),
            },
            {
                key: 'name',
                header: 'Nama',
                sortable: true,
                sortKey: 'name',
                cell: (row) => row.name,
            },
            {
                key: 'asset_type',
                header: 'Tipe',
                sortable: true,
                sortKey: 'asset_type',
                cell: (row) => <EnumBadge map={NETWORK_ASSET_TYPE} value={row.asset_type} />,
            },
            {
                key: 'serial_number',
                header: 'Nomor seri',
                cell: (row) =>
                    row.serial_number ? (
                        <span className="font-mono text-sm">{row.serial_number}</span>
                    ) : (
                        <EmptyCell />
                    ),
            },
            {
                key: 'status',
                header: 'Status',
                sortable: true,
                sortKey: 'status',
                cell: (row) => <EnumBadge map={NETWORK_ASSET_STATUS} value={row.status} />,
            },
            {
                key: 'location',
                header: 'Lokasi',
                cell: (row) => row.location?.name ?? <EmptyCell />,
            },
            {
                key: 'created_at',
                header: 'Dibuat',
                sortable: true,
                sortKey: 'created_at',
                cell: (row) => formatDate(row.created_at),
            },
            {
                key: 'actions',
                header: 'Aksi',
                className: 'text-right',
                cell: (row) => (
                    <DataTableActions showHref={route('admin.network-assets.show', row.id)} />
                ),
            },
        ],
        [],
    );

    const createButton = can('network_asset.create') ? (
        <Button asChild size="sm" variant="primary">
            <Link href={route('admin.network-assets.create')}>
                <Plus className="size-4" aria-hidden="true" />
                Buat Aset Jaringan
            </Link>
        </Button>
    ) : undefined;

    return (
        <AdminLayout title="Aset Jaringan">
            <div className="space-y-4">
                <PageHeader
                    title="Aset Jaringan"
                    description="Perangkat jaringan, statusnya, dan tempat pemasangannya."
                    actions={
                        <div className="flex flex-wrap gap-2">
                            <ExportMenu
                                exportUrl={route('admin.network-assets.export')}
                                params={params}
                                canExport={can('network_asset.export')}
                                formats={['csv', 'pdf']}
                            />
                            {createButton}
                        </div>
                    }
                />

                <div className="flex flex-col gap-3 rounded-lg border bg-card p-3 md:flex-row md:flex-wrap md:items-end">
                    <div className="min-w-48 flex-1">
                        <Input
                            id="na-search"
                            label="Cari"
                            value={params.search ?? ''}
                            onChange={(e) => set('search', e.target.value)}
                            placeholder="Kode, nama, atau nomor seri"
                        />
                    </div>
                    <NativeSelect
                        label="Tipe"
                        wrapperClassName="md:w-44"
                        value={params.asset_type ?? ''}
                        onChange={(e) => set('asset_type', e.target.value)}
                        options={typeFilterOptions}
                    />
                    <NativeSelect
                        label="Status"
                        wrapperClassName="md:w-44"
                        value={params.status ?? ''}
                        onChange={(e) => set('status', e.target.value)}
                        options={statusFilterOptions}
                    />
                </div>

                <DataTable
                    columns={columns}
                    data={assets.data}
                    emptyTitle="Belum ada aset jaringan"
                    emptyDescription="Catat router, OLT, ODP, dan perangkat lain agar pemasangan dan perawatannya terlacak."
                    emptyAction={createButton}
                    hasFilters={hasFilters}
                    onResetFilters={() => setMany({ search: '', asset_type: '', status: '' })}
                    itemLabel="aset jaringan"
                    loading={processing}
                    sortBy={sortBy}
                    sortDir={sortDir}
                    onSort={onSort}
                    pagination={pagination}
                    onPageChange={(page) => set('page', page)}
                    onPerPageChange={(n) => set('per_page', n)}
                />
            </div>
        </AdminLayout>
    );
}
