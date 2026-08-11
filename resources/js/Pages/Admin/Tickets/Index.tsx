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
import { formatDateTime } from '@/lib/format';

type Row = {
    id: number;
    number: string;
    subject: string;
    priority: string;
    status: string;
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
    tickets: Paginator<Row>;
    filters: {
        search?: string;
        priority?: string;
        status?: string;
        sort_by?: string;
        sort_dir?: string;
        per_page?: string | number;
    };
    priorityOptions?: string[];
    statusOptions?: string[];
};

export default function TicketsIndex({
    tickets,
    filters,
    priorityOptions = [],
    statusOptions = [],
}: Props) {
    const can = useCan();
    const { params, set, sortBy, sortDir, onSort } = useTableQuery(
        {
            search: filters.search ?? '',
            priority: filters.priority ?? '',
            status: filters.status ?? '',
            sort_by: filters.sort_by ?? '',
            sort_dir: filters.sort_dir ?? '',
            per_page: filters.per_page ?? tickets.per_page,
        },
        { only: ['tickets', 'filters'] },
    );

    const columns: DataTableColumn<Row>[] = useMemo(
        () => [
            {
                key: 'number',
                header: 'Number',
                sortable: true,
                sortKey: 'number',
                cell: (row) => (
                    <Link
                        href={route('admin.tickets.show', row.id)}
                        className="font-medium text-primary hover:underline"
                    >
                        {row.number}
                    </Link>
                ),
            },
            {
                key: 'subject',
                header: 'Subject',
                sortable: true,
                sortKey: 'subject',
                cell: (row) => row.subject,
            },
            {
                key: 'priority',
                header: 'Priority',
                sortable: true,
                sortKey: 'priority',
                cell: (row) => <StatusBadge status={row.priority} />,
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
                key: 'created_at',
                header: 'Created',
                sortable: true,
                sortKey: 'created_at',
                cell: (row) => formatDateTime(row.created_at),
            },
        ],
        [],
    );

    return (
        <AppLayout>
            <Head title="Tickets" />
            <div className="space-y-4 p-4 md:p-6">
                <PageHeader
                    title="Tickets"
                    description="Support tickets and escalations."
                    actions={
                        <div className="flex flex-wrap gap-2">
                            <ExportMenu
                                exportUrl={route('admin.tickets.export')}
                                params={params}
                                canExport={can('ticket.export')}
                            />
                            {can('ticket.create') && (
                                <Button asChild size="sm">
                                    <Link href={route('admin.tickets.create')}>
                                        <Plus className="size-4" />
                                        New ticket
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
                            htmlFor="tk-search"
                        >
                            Search
                        </label>
                        <Input
                            id="tk-search"
                            value={params.search ?? ''}
                            onChange={(e) => set('search', e.target.value)}
                            placeholder="Number or subject…"
                        />
                    </div>
                    <div className="space-y-1">
                        <label className="text-xs font-medium text-muted-foreground">
                            Priority
                        </label>
                        <Select
                            value={params.priority || '__all__'}
                            onValueChange={(v) => set('priority', v === '__all__' ? '' : v)}
                        >
                            <SelectTrigger className="w-[10rem]">
                                <SelectValue placeholder="All" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="__all__">All</SelectItem>
                                {priorityOptions.map((s) => (
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
                    data={tickets.data}
                    emptyTitle="No tickets"
                    emptyDescription="No tickets match the current filters."
                    sortBy={sortBy}
                    sortDir={sortDir}
                    onSort={onSort}
                    pagination={tickets}
                    onPageChange={(page) => set('page', page)}
                    onPerPageChange={(n) => set('per_page', n)}
                />
            </div>
        </AppLayout>
    );
}
