import { Link } from '@inertiajs/react';
import { useMemo } from 'react';
import AdminLayout from '@/Layouts/AdminLayout';
import { DataTableActions, PageHeader } from '@/Components/composite';
import { DataTable, type DataTableColumn } from '@/Components/composite/DataTable';
import { ExportMenu } from '@/Components/ui/ExportMenu';
import { Button } from '@/Components/ui/Button';
import { Input } from '@/Components/ui/Input';
import { useServerTable } from '@/hooks/useServerTable';
import { toPagination } from '@/lib/pagination';
import { formatDate } from '@/lib/format';

type Row = {
    id: string;
    name: string;
    code?: string | null;
    download_mbps?: number | string | null;
    upload_mbps?: number | string | null;
    is_active?: boolean;
    created_at: string | null;
};

type Props = {
    bandwidthProfiles: {
        data: Row[];
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
    };
    filters: {
        search?: string;
        sort?: string;
        direction?: string;
        per_page?: string | number;
    };
    can: { create: boolean; export: boolean };
};

export default function BandwidthProfilesIndex({ bandwidthProfiles, filters, can }: Props) {
    const { params, search, setSearch, setMany, set, sortBy, sortDir, onSort, processing } =
        useServerTable({
            url: route('admin.bandwidth-profiles.index'),
            filters,
            only: ['bandwidthProfiles', 'filters', 'can'],
        });

    const columns: DataTableColumn<Row>[] = useMemo(
        () => [
            {
                key: 'name',
                header: 'Nama',
                sortable: true,
                sortKey: 'name',
                cell: (row) => (
                    <Link
                        href={route('admin.bandwidth-profiles.edit', row.id)}
                        className="font-medium text-primary-text hover:underline"
                    >
                        {row.name}
                    </Link>
                ),
            },
            {
                key: 'download_mbps',
                header: 'Unduh (Mbps)',
                sortable: true,
                sortKey: 'download_mbps',
                headerClassName: 'text-right',
                className: 'text-right tabular-nums',
                cell: (row) => String(row.download_mbps ?? '-'),
            },
            {
                key: 'upload_mbps',
                header: 'Unggah (Mbps)',
                sortable: true,
                sortKey: 'upload_mbps',
                headerClassName: 'text-right',
                className: 'text-right tabular-nums',
                cell: (row) => String(row.upload_mbps ?? '-'),
            },
            {
                key: 'created_at',
                header: 'Dibuat',
                sortable: true,
                sortKey: 'created_at',
                className: 'whitespace-nowrap',
                cell: (row) => formatDate(row.created_at),
            },
            {
                key: 'actions',
                header: 'Aksi',
                cell: (row) => (
                    <DataTableActions
                        editHref={route('admin.bandwidth-profiles.edit', row.id)}
                        deleteHref={route('admin.bandwidth-profiles.destroy', row.id)}
                        deleteMessage={`Hapus ${row.name}?`}
                        deleteDescription={`Profil bandwidth "${row.name}" akan dihapus permanen.`}
                    />
                ),
            },
        ],
        [],
    );

    const createButton = can.create ? (
        <Button asChild variant="primary" size="sm">
            <Link href={route('admin.bandwidth-profiles.create')}>Buat Profil Bandwidth</Link>
        </Button>
    ) : undefined;

    return (
        <AdminLayout title="Profil Bandwidth">
            <div className="space-y-4">
                <PageHeader
                    title="Profil Bandwidth"
                    subtitle="Kecepatan unduh dan unggah serta rasio kontensi yang dipakai paket layanan."
                    actions={
                        <div className="flex flex-wrap gap-2">
                            {can.export ? (
                                <ExportMenu
                                    exportUrl={route('admin.bandwidth-profiles.export')}
                                    params={params}
                                    formats={['csv', 'pdf']}
                                    canExport={can.export}
                                />
                            ) : null}
                            {createButton}
                        </div>
                    }
                />

                <div className="max-w-sm">
                    <Input
                        id="bw-search"
                        label="Cari"
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                        placeholder="Nama atau tipe"
                    />
                </div>

                <DataTable
                    columns={columns}
                    pagination={toPagination(bandwidthProfiles)}
                    sortBy={sortBy}
                    sortDir={sortDir}
                    onSort={onSort}
                    onPageChange={(page) => set('page', page)}
                    onPerPageChange={(perPage) => set('per_page', perPage)}
                    loading={processing}
                    itemLabel="profil bandwidth"
                    hasFilters={Boolean(params.search)}
                    onResetFilters={() => setMany({ search: '' })}
                    emptyTitle="Belum ada profil bandwidth"
                    emptyDescription="Profil bandwidth menentukan kecepatan dan rasio kontensi sebuah paket layanan."
                    emptyAction={createButton}
                />
            </div>
        </AdminLayout>
    );
}
