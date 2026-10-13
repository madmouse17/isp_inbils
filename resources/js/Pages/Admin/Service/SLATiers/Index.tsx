import { Head, Link } from '@inertiajs/react';
import { useMemo } from 'react';
import { Plus } from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import { DataTableActions, PageHeader } from '@/Components/composite';
import { DataTable, type DataTableColumn } from '@/Components/composite/DataTable';
import { ExportMenu } from '@/Components/ui/ExportMenu';
import { Button } from '@/Components/ui/Button';
import { Input } from '@/Components/ui/Input';
import { useServerTable } from '@/hooks/useServerTable';
import { toPagination } from '@/lib/pagination';
import { formatDate, formatNumber } from '@/lib/format';

type Row = {
    id: number;
    name: string;
    response_time_hours?: number | string | null;
    resolution_time_hours?: number | string | null;
    response_on_time_rate_30d: number | null;
    resolution_on_time_rate_30d: number | null;
    tickets_30d: number;
    created_at: string | null;
};

type Props = {
    slaTiers: {
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

export default function SlaTiersIndex({ slaTiers, filters, can }: Props) {
    const { params, set, sortBy, sortDir, onSort, processing } = useServerTable({
        url: route('admin.sla-tiers.index'),
        filters,
        only: ['slaTiers', 'filters', 'can'],
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
                        href={route('admin.sla-tiers.edit', row.id)}
                        className="font-medium text-primary hover:underline"
                    >
                        {row.name}
                    </Link>
                ),
            },
            {
                key: 'response_time_hours',
                header: 'Target respons (jam)',
                sortable: true,
                sortKey: 'response_time_hours',
                className: 'text-right tabular-nums',
                cell: (row) => String(row.response_time_hours ?? '—'),
            },
            {
                key: 'resolution_time_hours',
                header: 'Target penyelesaian (jam)',
                sortable: true,
                sortKey: 'resolution_time_hours',
                className: 'text-right tabular-nums',
                cell: (row) => String(row.resolution_time_hours ?? '—'),
            },
            {
                key: 'response_on_time_rate_30d',
                header: 'Respons tepat waktu (30 hari)',
                className: 'text-right tabular-nums',
                cell: (row) =>
                    row.response_on_time_rate_30d === null
                        ? '—'
                        : `${row.response_on_time_rate_30d}%`,
            },
            {
                key: 'resolution_on_time_rate_30d',
                header: 'Penyelesaian tepat waktu (30 hari)',
                className: 'text-right tabular-nums',
                cell: (row) =>
                    row.resolution_on_time_rate_30d === null
                        ? '—'
                        : `${row.resolution_on_time_rate_30d}%`,
            },
            {
                key: 'tickets_30d',
                header: 'Tiket (30 hari)',
                className: 'text-right tabular-nums',
                cell: (row) => formatNumber(row.tickets_30d),
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
                cell: (row) => (
                    <DataTableActions
                        editHref={route('admin.sla-tiers.edit', row.id)}
                        deleteHref={route('admin.sla-tiers.destroy', row.id)}
                        deleteMessage={`Hapus ${row.name}?`}
                        deleteDescription={`Tingkat SLA "${row.name}" akan dihapus permanen.`}
                    />
                ),
            },
        ],
        [],
    );

    return (
        <AppLayout>
            <Head title="Tingkat SLA" />
            <div className="space-y-4 p-4 md:p-6">
                <PageHeader
                    title="Tingkat SLA"
                    description="Target layanan per paket. Hasil diukur otomatis dari tiket. Tiket urgent memakai setengah dari target."
                    actions={
                        <div className="flex flex-wrap gap-2">
                            {can.export ? (
                                <ExportMenu
                                    exportUrl={route('admin.sla-tiers.export')}
                                    params={params}
                                    canExport={can.export}
                                />
                            ) : null}
                            {can.create ? (
                                <Button asChild variant="success" size="sm">
                                    <Link href={route('admin.sla-tiers.create')}>
                                        <Plus className="size-4" />
                                        Tingkat baru
                                    </Link>
                                </Button>
                            ) : null}
                        </div>
                    }
                />

                <div className="flex flex-col gap-3 rounded-lg border bg-card p-3 md:flex-row md:items-end">
                    <div className="space-y-1 min-w-48 flex-1">
                        <label
                            className="text-xs font-medium text-muted-foreground"
                            htmlFor="sla-search"
                        >
                            Cari
                        </label>
                        <Input
                            id="sla-search"
                            value={params.search ?? ''}
                            onChange={(e) => set('search', e.target.value)}
                            placeholder="Nama…"
                        />
                    </div>
                </div>

                <DataTable
                    columns={columns}
                    pagination={toPagination(slaTiers)}
                    sortBy={sortBy}
                    sortDir={sortDir}
                    onSort={onSort}
                    onPageChange={(page) => set('page', page)}
                    onPerPageChange={(perPage) => set('per_page', perPage)}
                    emptyTitle="Belum ada tingkat SLA"
                    emptyDescription="Tidak ada tingkat SLA yang cocok dengan filter."
                    loading={processing}
                />
            </div>
        </AppLayout>
    );
}
