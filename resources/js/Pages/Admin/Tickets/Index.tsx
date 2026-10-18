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
import { formatDateTime } from '@/lib/format';
import { toPagination, type PaginationLike } from '@/lib/pagination';
import { optionsOf, PRIORITY, TICKET_STATUS } from '@/lib/statuses';

type Row = {
    id: string;
    code: string;
    title: string;
    priority: string;
    status: string;
    created_at: string | null;
    customer?: { id: string; name: string } | null;
    assignee?: { id: string; name: string } | null;
};

type Props = {
    tickets: PaginationLike<Row>;
    filters: {
        search?: string;
        priority?: string;
        status?: string;
        sort_by?: string;
        sort_dir?: string;
        per_page?: string | number;
    };
};

export default function TicketsIndex({ tickets, filters }: Props) {
    const can = useCan();
    const company = useCompany();
    const pagination = toPagination(tickets);
    const { params, set, sortBy, sortDir, onSort, processing } = useTableQuery(
        {
            ...filters,
            search: filters.search ?? '',
            priority: filters.priority ?? '',
            status: filters.status ?? '',
            sort_by: filters.sort_by ?? '',
            sort_dir: filters.sort_dir ?? '',
            per_page: filters.per_page ?? pagination.per_page,
        },
        { only: ['tickets', 'filters'] },
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
                        href={route('admin.tickets.show', row.id)}
                        className="font-medium text-primary-text hover:underline"
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
                key: 'priority',
                header: 'Prioritas',
                sortable: true,
                sortKey: 'priority',
                cell: (row) => <EnumBadge map={PRIORITY} value={row.priority} />,
            },
            {
                key: 'status',
                header: 'Status',
                sortable: true,
                sortKey: 'status',
                cell: (row) => <EnumBadge map={TICKET_STATUS} value={row.status} />,
            },
            {
                key: 'customer',
                header: 'Pelanggan',
                cell: (row) => row.customer?.name ?? '-',
            },
            {
                key: 'created_at',
                header: 'Dibuat',
                sortable: true,
                sortKey: 'created_at',
                cell: (row) => formatDateTime(row.created_at, company),
            },
        ],
        [company],
    );

    const hasFilters = Boolean(params.search || params.priority || params.status);
    const resetFilters = () => {
        set('search', '');
        set('priority', '');
        set('status', '');
    };
    const create = can('ticket.create') ? (
        <Button asChild size="sm" variant="primary">
            <Link href={route('admin.tickets.create')}>
                <Plus className="size-4" aria-hidden="true" />
                Buat tiket
            </Link>
        </Button>
    ) : undefined;

    return (
        <AdminLayout title="Tiket">
            <div className="space-y-4">
                <PageHeader
                    title="Tiket"
                    description="Keluhan dan gangguan pelanggan, dari yang baru masuk sampai ditutup."
                    actions={
                        <div className="flex flex-wrap gap-2">
                            <ExportMenu
                                exportUrl={route('admin.tickets.export')}
                                params={params}
                                canExport={can('ticket.export')}
                                formats={['csv', 'pdf']}
                            />
                            {create}
                        </div>
                    }
                />

                <div className="flex flex-col gap-3 rounded-lg border bg-card p-3 md:flex-row md:flex-wrap md:items-end">
                    <div className="min-w-48 flex-1">
                        <Input
                            id="tk-search"
                            label="Cari"
                            value={params.search ?? ''}
                            onChange={(e) => set('search', e.target.value)}
                            placeholder="Nomor atau judul..."
                        />
                    </div>
                    <NativeSelect
                        id="tk-priority"
                        label="Prioritas"
                        wrapperClassName="w-full sm:w-48"
                        value={params.priority ?? ''}
                        onChange={(e) => set('priority', e.target.value)}
                        options={[{ value: '', label: 'Semua prioritas' }, ...optionsOf(PRIORITY)]}
                    />
                    <NativeSelect
                        id="tk-status"
                        label="Status"
                        wrapperClassName="w-full sm:w-48"
                        value={params.status ?? ''}
                        onChange={(e) => set('status', e.target.value)}
                        options={[
                            { value: '', label: 'Semua status' },
                            ...optionsOf(TICKET_STATUS),
                        ]}
                    />
                </div>

                <DataTable
                    columns={columns}
                    data={tickets.data}
                    emptyTitle="Belum ada tiket"
                    emptyDescription="Tiket baru dari pelanggan, NOC, atau tim internal muncul di sini."
                    emptyAction={create}
                    hasFilters={hasFilters}
                    onResetFilters={resetFilters}
                    itemLabel="tiket"
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
