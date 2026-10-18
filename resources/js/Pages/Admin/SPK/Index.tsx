import { Link } from '@inertiajs/react';
import { useMemo } from 'react';
import { Plus } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import { DataTable, PageHeader } from '@/Components/composite';
import type { DataTableColumn } from '@/Components/composite';
import { Button, EnumBadge, ExportMenu, Input, NativeSelect } from '@/Components/ui';
import { useCan } from '@/hooks/useCan';
import { useCompany } from '@/hooks/useCompany';
import { useTableQuery } from '@/hooks/useTableQuery';
import { formatDate } from '@/lib/format';
import { toPagination, type PaginationLike } from '@/lib/pagination';
import { optionsOf, WORK_ORDER_STATUS, WORK_ORDER_TYPE } from '@/lib/statuses';

type Row = {
    id: string;
    code: string;
    title: string;
    type: string;
    status: string;
    scheduled_date?: string | null;
    created_at: string | null;
    customer?: { id: string; name: string } | null;
    assignee?: { id: string; name: string } | null;
};

type Props = {
    workOrders: PaginationLike<Row>;
    filters: {
        search?: string;
        type?: string;
        status?: string;
        sort_by?: string;
        sort_dir?: string;
        per_page?: string | number;
    };
};

export default function SpkIndex({ workOrders, filters }: Props) {
    const can = useCan();
    const company = useCompany();
    const pagination = toPagination(workOrders);
    const { params, set, sortBy, sortDir, onSort, processing } = useTableQuery(
        {
            ...filters,
            search: filters.search ?? '',
            type: filters.type ?? '',
            status: filters.status ?? '',
            sort_by: filters.sort_by ?? '',
            sort_dir: filters.sort_dir ?? '',
            per_page: filters.per_page ?? pagination.per_page,
        },
        { only: ['workOrders', 'filters'] },
    );

    const columns: DataTableColumn<Row>[] = useMemo(
        () => [
            {
                key: 'code',
                header: 'Nomor',
                sortable: true,
                sortKey: 'code',
                cell: (row) => (
                    <Link
                        href={route('admin.spk.show', row.id)}
                        className="font-medium text-primary-text hover:underline"
                        aria-label={`Buka SPK ${row.code}`}
                    >
                        {row.code}
                    </Link>
                ),
            },
            {
                key: 'title',
                header: 'Judul',
                sortable: true,
                sortKey: 'title',
                cell: (row) => row.title,
            },
            {
                key: 'type',
                header: 'Jenis',
                sortable: true,
                sortKey: 'type',
                cell: (row) => <EnumBadge map={WORK_ORDER_TYPE} value={row.type} />,
            },
            {
                key: 'status',
                header: 'Status',
                sortable: true,
                sortKey: 'status',
                cell: (row) => <EnumBadge map={WORK_ORDER_STATUS} value={row.status} />,
            },
            {
                key: 'customer',
                header: 'Pelanggan',
                cell: (row) => row.customer?.name ?? '-',
            },
            {
                key: 'scheduled_date',
                header: 'Jadwal',
                sortable: true,
                sortKey: 'scheduled_date',
                cell: (row) => formatDate(row.scheduled_date ?? null, company),
            },
            {
                key: 'created_at',
                header: 'Dibuat',
                sortable: true,
                sortKey: 'created_at',
                cell: (row) => formatDate(row.created_at, company),
            },
        ],
        [company],
    );

    const hasFilters = Boolean(params.search || params.type || params.status);
    const resetFilters = () => {
        set('search', '');
        set('type', '');
        set('status', '');
    };
    const create = can('spk.create') ? (
        <Button asChild size="sm" variant="primary">
            <Link href={route('admin.spk.create')}>
                <Plus className="size-4" aria-hidden="true" />
                Buat SPK
            </Link>
        </Button>
    ) : undefined;

    return (
        <AdminLayout title="SPK">
            <div className="space-y-4">
                <PageHeader
                    title="SPK"
                    description="Pekerjaan lapangan, dari instalasi sampai perawatan, beserta teknisinya."
                    actions={
                        <div className="flex flex-wrap gap-2">
                            <ExportMenu
                                exportUrl={route('admin.spk.export')}
                                params={params}
                                canExport={can('spk.export')}
                                formats={['csv', 'pdf']}
                            />
                            {create}
                        </div>
                    }
                />

                <div className="flex flex-col gap-3 rounded-lg border bg-card p-3 md:flex-row md:flex-wrap md:items-end">
                    <div className="min-w-48 flex-1">
                        <Input
                            id="spk-search"
                            label="Cari"
                            value={params.search ?? ''}
                            onChange={(e) => set('search', e.target.value)}
                            placeholder="Nomor atau judul..."
                        />
                    </div>
                    <NativeSelect
                        id="spk-type"
                        label="Jenis"
                        wrapperClassName="w-full sm:w-52"
                        value={params.type ?? ''}
                        onChange={(e) => set('type', e.target.value)}
                        options={[
                            { value: '', label: 'Semua jenis' },
                            ...optionsOf(WORK_ORDER_TYPE),
                        ]}
                    />
                    <NativeSelect
                        id="spk-status"
                        label="Status"
                        wrapperClassName="w-full sm:w-52"
                        value={params.status ?? ''}
                        onChange={(e) => set('status', e.target.value)}
                        options={[
                            { value: '', label: 'Semua status' },
                            ...optionsOf(WORK_ORDER_STATUS),
                        ]}
                    />
                </div>

                <DataTable
                    columns={columns}
                    data={workOrders.data}
                    emptyTitle="Belum ada SPK"
                    emptyDescription="SPK dibuat dari halaman ini, dari tiket, atau dari langganan baru."
                    emptyAction={create}
                    hasFilters={hasFilters}
                    onResetFilters={resetFilters}
                    itemLabel="SPK"
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
