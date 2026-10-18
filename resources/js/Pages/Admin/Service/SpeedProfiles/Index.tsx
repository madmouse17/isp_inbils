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
    download_max_mbps?: number | string | null;
    upload_max_mbps?: number | string | null;
    created_at: string | null;
};

type Props = {
    speedProfiles: {
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

export default function SpeedProfilesIndex({ speedProfiles, filters, can }: Props) {
    const { params, search, setSearch, setMany, set, sortBy, sortDir, onSort, processing } =
        useServerTable({
            url: route('admin.speed-profiles.index'),
            filters,
            only: ['speedProfiles', 'filters', 'can'],
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
                        href={route('admin.speed-profiles.edit', row.id)}
                        className="font-medium text-primary-text hover:underline"
                    >
                        {row.name}
                    </Link>
                ),
            },
            {
                key: 'download_max_mbps',
                header: 'Unduh maksimum (Mbps)',
                sortable: true,
                sortKey: 'download_max_mbps',
                headerClassName: 'text-right',
                className: 'text-right tabular-nums',
                cell: (row) => String(row.download_max_mbps ?? '-'),
            },
            {
                key: 'upload_max_mbps',
                header: 'Unggah maksimum (Mbps)',
                sortable: true,
                sortKey: 'upload_max_mbps',
                headerClassName: 'text-right',
                className: 'text-right tabular-nums',
                cell: (row) => String(row.upload_max_mbps ?? '-'),
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
                        editHref={route('admin.speed-profiles.edit', row.id)}
                        deleteHref={route('admin.speed-profiles.destroy', row.id)}
                        deleteMessage={`Hapus ${row.name}?`}
                        deleteDescription={`Profil kecepatan "${row.name}" akan dihapus permanen.`}
                    />
                ),
            },
        ],
        [],
    );

    const createButton = can.create ? (
        <Button asChild variant="primary" size="sm">
            <Link href={route('admin.speed-profiles.create')}>Buat Profil Kecepatan</Link>
        </Button>
    ) : undefined;

    return (
        <AdminLayout title="Profil Kecepatan">
            <div className="space-y-4">
                <PageHeader
                    title="Profil Kecepatan"
                    subtitle="Label kecepatan yang dijual pada paket layanan, beserta profil RADIUS-nya."
                    actions={
                        <div className="flex flex-wrap gap-2">
                            {can.export ? (
                                <ExportMenu
                                    exportUrl={route('admin.speed-profiles.export')}
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
                        id="sp-search"
                        label="Cari"
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                        placeholder="Nama atau profil RADIUS"
                    />
                </div>

                <DataTable
                    columns={columns}
                    pagination={toPagination(speedProfiles)}
                    sortBy={sortBy}
                    sortDir={sortDir}
                    onSort={onSort}
                    onPageChange={(page) => set('page', page)}
                    onPerPageChange={(perPage) => set('per_page', perPage)}
                    loading={processing}
                    itemLabel="profil kecepatan"
                    hasFilters={Boolean(params.search)}
                    onResetFilters={() => setMany({ search: '' })}
                    emptyTitle="Belum ada profil kecepatan"
                    emptyDescription="Profil kecepatan menentukan batas unduh dan unggah, dan dipakai oleh paket layanan."
                    emptyAction={createButton}
                />
            </div>
        </AdminLayout>
    );
}
