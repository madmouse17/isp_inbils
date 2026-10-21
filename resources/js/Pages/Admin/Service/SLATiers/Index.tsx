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
import { formatDate, formatNumber, formatPercent } from '@/lib/format';
import { LIFECYCLE_STATE, lifecycleStateOf } from '@/lib/statuses';
import type { LifecycleFields } from '@/types/models';
import { SLA_CALENDAR_LABEL, type SlaCalendar } from './components/CalendarField';

type Row = LifecycleFields & {
    id: string;
    name: string;
    response_time_hours?: number | string | null;
    resolution_time_hours?: number | string | null;
    calendar: SlaCalendar;
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
        status?: string;
        sort?: string;
        direction?: string;
        per_page?: string | number;
    };
    can: { create: boolean; update: boolean; delete: boolean; export: boolean };
};

export default function SlaTiersIndex({ slaTiers, filters, can }: Props) {
    const { params, search, setSearch, setMany, set, sortBy, sortDir, onSort, processing } =
        useServerTable({
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
                    <div className="space-y-0.5">
                        {row.deleted_at ? (
                            <span className="font-medium text-muted-foreground">{row.name}</span>
                        ) : (
                            <Link
                                href={route('admin.sla-tiers.edit', row.id)}
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
                key: 'response_time_hours',
                header: 'Target respons (jam)',
                sortable: true,
                sortKey: 'response_time_hours',
                className: 'text-right tabular-nums',
                cell: (row) => String(row.response_time_hours ?? '-'),
            },
            {
                key: 'resolution_time_hours',
                header: 'Target penyelesaian (jam)',
                sortable: true,
                sortKey: 'resolution_time_hours',
                className: 'text-right tabular-nums',
                cell: (row) => String(row.resolution_time_hours ?? '-'),
            },
            {
                key: 'calendar',
                header: 'Hitungan tenggat',
                cell: (row) => SLA_CALENDAR_LABEL[row.calendar],
            },
            {
                key: 'response_on_time_rate_30d',
                header: 'Respons tepat waktu (30 hari)',
                className: 'text-right tabular-nums',
                cell: (row) => formatPercent(row.response_on_time_rate_30d),
            },
            {
                key: 'resolution_on_time_rate_30d',
                header: 'Penyelesaian tepat waktu (30 hari)',
                className: 'text-right tabular-nums',
                cell: (row) => formatPercent(row.resolution_on_time_rate_30d),
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
                key: 'status',
                header: 'Status',
                cell: (row) => <EnumBadge map={LIFECYCLE_STATE} value={lifecycleStateOf(row)} />,
            },
            {
                key: 'actions',
                header: 'Aksi',
                cell: (row) => (
                    <DataTableActions
                        editHref={route('admin.sla-tiers.edit', row.id)}
                        lifecycle={{
                            row,
                            name: row.name,
                            noun: 'Tingkat SLA',
                            routeBase: 'admin.sla-tiers',
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
            <Link href={route('admin.sla-tiers.create')}>Buat Tingkat SLA</Link>
        </Button>
    ) : undefined;

    return (
        <AdminLayout title="Tingkat SLA">
            <div className="space-y-4">
                <PageHeader
                    title="Tingkat SLA"
                    subtitle="Target layanan per paket. Hasil diukur otomatis dari tiket. Tiket mendesak memakai setengah dari target."
                    actions={
                        <div className="flex flex-wrap gap-2">
                            {can.export ? (
                                <ExportMenu
                                    exportUrl={route('admin.sla-tiers.export')}
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
                            id="sla-search"
                            label="Cari"
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            placeholder="Nama tingkat"
                        />
                    </div>
                    <StatusFilter
                        id="sla-status"
                        value={params.status ?? ''}
                        onChange={(value) => set('status', value)}
                        deactivatable
                    />
                </div>

                <DataTable
                    columns={columns}
                    pagination={toPagination(slaTiers)}
                    sortBy={sortBy}
                    sortDir={sortDir}
                    onSort={onSort}
                    onPageChange={(page) => set('page', page)}
                    onPerPageChange={(perPage) => set('per_page', perPage)}
                    loading={processing}
                    itemLabel="tingkat SLA"
                    hasFilters={Boolean(params.search || params.status)}
                    onResetFilters={() => setMany({ search: '', status: '' })}
                    emptyTitle="Belum ada tingkat SLA"
                    emptyDescription="Tingkat SLA menetapkan target respons dan penyelesaian tiket untuk paket layanan."
                    emptyAction={createButton}
                />
            </div>
        </AdminLayout>
    );
}
