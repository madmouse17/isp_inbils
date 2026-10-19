import { Link } from '@inertiajs/react';
import { useMemo } from 'react';
import AdminLayout from '@/Layouts/AdminLayout';
import { DataTableActions, PageHeader, StatusFilter } from '@/Components/composite';
import { DataTable, type DataTableColumn } from '@/Components/composite/DataTable';
import { UsageLine } from '@/Components/composite/UsageLine';
import { ExportMenu } from '@/Components/ui/ExportMenu';
import { Button } from '@/Components/ui/Button';
import { EnumBadge } from '@/Components/ui/EnumBadge';
import { Input } from '@/Components/ui/Input';
import { useServerTable } from '@/hooks/useServerTable';
import { toPagination } from '@/lib/pagination';
import { formatDate } from '@/lib/format';
import { LIFECYCLE_STATE, lifecycleStateOf } from '@/lib/statuses';
import type { LifecycleFields } from '@/types/models';

type Row = LifecycleFields & {
    id: string;
    name: string;
    code?: string | null;
    download_mbps?: number | string | null;
    upload_mbps?: number | string | null;
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
        status?: string;
        sort?: string;
        direction?: string;
        per_page?: string | number;
    };
    can: { create: boolean; update: boolean; delete: boolean; export: boolean };
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
                    <div className="space-y-0.5">
                        {row.deleted_at ? (
                            <span className="font-medium text-muted-foreground">{row.name}</span>
                        ) : (
                            <Link
                                href={route('admin.bandwidth-profiles.edit', row.id)}
                                className="font-medium text-primary-text hover:underline"
                            >
                                {row.name}
                            </Link>
                        )}
                        <UsageLine references={row.references} />
                    </div>
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
                key: 'status',
                header: 'Status',
                cell: (row) => <EnumBadge map={LIFECYCLE_STATE} value={lifecycleStateOf(row)} />,
            },
            {
                key: 'actions',
                header: 'Aksi',
                cell: (row) => (
                    <DataTableActions
                        editHref={route('admin.bandwidth-profiles.edit', row.id)}
                        lifecycle={{
                            row,
                            name: row.name,
                            noun: 'Profil bandwidth',
                            routeBase: 'admin.bandwidth-profiles',
                            can: { update: can.update, delete: can.delete },
                        }}
                    />
                ),
            },
        ],
        [can.update, can.delete],
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

                <div className="flex flex-wrap items-end gap-3">
                    <div className="min-w-56 flex-1 sm:max-w-sm">
                        <Input
                            id="bw-search"
                            label="Cari"
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            placeholder="Nama atau tipe"
                        />
                    </div>
                    <StatusFilter
                        id="bw-status"
                        value={params.status ?? ''}
                        onChange={(value) => set('status', value)}
                        deactivatable
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
                    hasFilters={Boolean(params.search || params.status)}
                    onResetFilters={() => setMany({ search: '', status: '' })}
                    emptyTitle="Belum ada profil bandwidth"
                    emptyDescription="Profil bandwidth menentukan kecepatan dan rasio kontensi sebuah paket layanan."
                    emptyAction={createButton}
                />
            </div>
        </AdminLayout>
    );
}
