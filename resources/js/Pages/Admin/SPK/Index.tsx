import { Link } from '@inertiajs/react';
import { useMemo } from 'react';
import { Plus } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import { PageHeader } from '@/Components/composite/PageHeader';
import { StatusBadge } from '@/Components/composite/StatusBadge';
import { DataTable, type DataTableColumn } from '@/Components/composite/DataTable';
import { ExportMenu } from '@/Components/ui/ExportMenu';
import { Button } from '@/Components/ui/Button';
import { Input } from '@/Components/ui/Input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/Components/ui/Select';
import { useCan } from '@/hooks/useCan';
import { useTableQuery } from '@/hooks/useTableQuery';
import { formatDate } from '@/lib/format';
import { toPagination, type PaginationLike } from '@/lib/pagination';

type Row = {
    id: number;
    code: string;
    type: string;
    status: string;
    scheduled_date?: string | null;
    created_at: string | null;
    customer?: { id: number; name: string } | null;
    assignee?: { id: number; name: string } | null;
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
    typeOptions?: string[];
    statusOptions?: string[];
};

export default function SpkIndex({
    workOrders,
    filters,
    typeOptions = [],
    statusOptions = [],
}: Props) {
    const can = useCan();
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
                header: 'Number',
                sortable: true,
                sortKey: 'code',
                cell: (row) => (
                    <Link
                        href={route('admin.spk.show', row.id)}
                        className="font-medium text-primary hover:underline"
                        aria-label={`Open SPK ${row.code}`}
                    >
                        {row.code}
                    </Link>
                ),
            },
            {
                key: 'type',
                header: 'Type',
                sortable: true,
                sortKey: 'type',
                cell: (row) => row.type,
            },
            {
                key: 'status',
                header: 'Status',
                sortable: true,
                sortKey: 'status',
                cell: (row) => <StatusBadge status={row.status} />,
            },
            {
                key: 'customer',
                header: 'Customer',
                cell: (row) => row.customer?.name ?? '-',
            },
            {
                key: 'scheduled_date',
                header: 'Scheduled',
                sortable: true,
                sortKey: 'scheduled_date',
                cell: (row) => formatDate(row.scheduled_date ?? null),
            },
            {
                key: 'created_at',
                header: 'Created',
                sortable: true,
                sortKey: 'created_at',
                cell: (row) => formatDate(row.created_at),
            },
        ],
        [],
    );

    return (
        <AdminLayout title="SPK">
            <div className="space-y-4">
                <PageHeader
                    title="SPK"
                    description="Field work orders and installation jobs."
                    actions={
                        <div className="flex flex-wrap gap-2">
                            <ExportMenu
                                exportUrl={route('admin.spk.export')}
                                params={params}
                                canExport={can('spk.export')}
                                formats={['csv', 'pdf']}
                            />
                            {can('spk.create') && (
                                <Button asChild size="sm" variant="primary">
                                    <Link href={route('admin.spk.create')}>
                                        <Plus className="size-4" />
                                        New SPK
                                    </Link>
                                </Button>
                            )}
                        </div>
                    }
                />

                <div className="flex flex-col gap-3 rounded-lg border bg-card p-3 md:flex-row md:flex-wrap md:items-end">
                    <div className="space-y-1 min-w-48 flex-1">
                        <label
                            className="text-xs font-medium text-muted-foreground"
                            htmlFor="spk-search"
                        >
                            Search
                        </label>
                        <Input
                            id="spk-search"
                            value={params.search ?? ''}
                            onChange={(e) => set('search', e.target.value)}
                            placeholder="Number or title…"
                        />
                    </div>
                    <div className="space-y-1">
                        <label className="text-xs font-medium text-muted-foreground">Type</label>
                        <Select
                            value={params.type || '__all__'}
                            onValueChange={(v) => set('type', v === '__all__' ? '' : v)}
                        >
                            <SelectTrigger aria-label="Type" className="w-40">
                                <SelectValue placeholder="All" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="__all__">All</SelectItem>
                                {typeOptions.map((s) => (
                                    <SelectItem key={s} value={s}>
                                        {s}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>
                    <div className="space-y-1">
                        <label className="text-xs font-medium text-muted-foreground">Status</label>
                        <Select
                            value={params.status || '__all__'}
                            onValueChange={(v) => set('status', v === '__all__' ? '' : v)}
                        >
                            <SelectTrigger aria-label="Status" className="w-40">
                                <SelectValue placeholder="All" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="__all__">All</SelectItem>
                                {statusOptions.map((s) => (
                                    <SelectItem key={s} value={s}>
                                        {s}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>
                </div>

                <DataTable
                    columns={columns}
                    data={workOrders.data}
                    emptyTitle="No work orders"
                    emptyDescription="No SPK/work orders match the current filters."
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
