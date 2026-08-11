import { Head, Link } from '@inertiajs/react';
import { useMemo } from 'react';
import { Plus } from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import { PageHeader } from '@/Components/composite/PageHeader';
import { StatusBadge } from '@/Components/composite/StatusBadge';
import { DataTable, type DataTableColumn } from '@/Components/composite/DataTable';
import { ExportMenu } from '@/Components/ExportMenu';
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
import { formatDate, formatDateTime } from '@/lib/format';

type Row = {
    id: number;
    code: string;
    type: string;
    status: string;
    scheduled_at?: string | null;
    created_at: string | null;
    customer?: { id: number; name: string } | null;
    assignee?: { id: number; name: string } | null;
};

type PaginatorLink = { url: string | null; label: string; active: boolean };
type Paginator<T> = {
    data: T[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
    path?: string;
    links?: PaginatorLink[];
    first_page_url?: string;
    last_page_url?: string;
    next_page_url?: string | null;
    prev_page_url?: string | null;
};

type Props = {
    workOrders: Paginator<Row>;
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
    const { params, set, sortBy, sortDir, onSort } = useTableQuery(
        {
            search: filters.search ?? '',
            type: filters.type ?? '',
            status: filters.status ?? '',
            sort_by: filters.sort_by ?? '',
            sort_dir: filters.sort_dir ?? '',
            per_page: filters.per_page ?? workOrders.per_page,
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
                cell: (row) => row.customer?.name ?? '—',
            },
            {
                key: 'scheduled_at',
                header: 'Scheduled',
                sortable: true,
                sortKey: 'scheduled_at',
                cell: (row) => formatDateTime(row.scheduled_at ?? null),
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
        <AppLayout>
            <Head title="SPK / Work Orders" />
            <div className="space-y-4 p-4 md:p-6">
                <PageHeader
                    title="SPK / Work Orders"
                    description="Field work orders and installation jobs."
                    actions={
                        <div className="flex flex-wrap gap-2">
                            <ExportMenu
                                exportUrl={route('admin.spk.export')}
                                params={params}
                                canExport={can('spk.export')}
                            />
                            {can('spk.create') && (
                                <Button asChild size="sm">
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
                    <div className="min-w-[12rem] flex-1 space-y-1">
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
                            placeholder="Number or customer…"
                        />
                    </div>
                    <div className="space-y-1">
                        <label className="text-xs font-medium text-muted-foreground">Type</label>
                        <Select
                            value={params.type || '__all__'}
                            onValueChange={(v) => set('type', v === '__all__' ? '' : v)}
                        >
                            <SelectTrigger className="w-[10rem]">
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
                            <SelectTrigger className="w-[10rem]">
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
                    sortBy={sortBy}
                    sortDir={sortDir}
                    onSort={onSort}
                    pagination={workOrders}
                    onPageChange={(page) => set('page', page)}
                    onPerPageChange={(n) => set('per_page', n)}
                />
            </div>
        </AppLayout>
    );
}
