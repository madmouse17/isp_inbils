import { Head, Link } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import { Plus } from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import { PageHeader } from '@/Components/composite/PageHeader';
import { StatusBadge } from '@/Components/composite/StatusBadge';
import { DataTable, type DataTableColumn } from '@/Components/composite/DataTable';
import { ExportMenu } from '@/Components/ExportMenu';
import { Button } from '@/Components/ui/Button';
import { Input } from '@/Components/ui/Input';
import GenerateDialog from '@/Pages/Admin/Billing/Invoices/GenerateDialog';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/Components/ui/Select';
import { useCan } from '@/hooks/useCan';
import { useTableQuery } from '@/hooks/useTableQuery';
import { formatDate, formatMoney } from '@/lib/format';

type InvoiceRow = {
    id: number;
    number: string;
    status: string;
    issue_date: string | null;
    due_date: string | null;
    total: string | number;
    paid_amount: string | number;
    sisa: string | number;
    customer?: { id: number; name: string; code: string } | null;
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
    invoices: Paginator<InvoiceRow>;
    filters: {
        search?: string;
        status?: string;
        customer_id?: string;
        date_from?: string;
        date_to?: string;
        sort_by?: string;
        sort_dir?: string;
        per_page?: string | number;
    };
    statusOptions: string[];
};

const STATUS_LABEL: Record<string, string> = {
    draft: 'Draft',
    issued: 'Issued',
    partial: 'Partial',
    paid: 'Paid',
    void: 'Void',
    written_off: 'Written off',
};

export default function InvoicesIndex({ invoices, filters, statusOptions }: Props) {
    const can = useCan();
    const [generateOpen, setGenerateOpen] = useState(false);
    const { params, set, sortBy, sortDir, onSort } = useTableQuery(
        {
            search: filters.search ?? '',
            status: filters.status ?? '',
            customer_id: filters.customer_id ?? '',
            date_from: filters.date_from ?? '',
            date_to: filters.date_to ?? '',
            sort_by: filters.sort_by ?? '',
            sort_dir: filters.sort_dir ?? '',
            per_page: filters.per_page ?? invoices.per_page,
        },
        { only: ['invoices', 'filters'] },
    );

    const columns: DataTableColumn<InvoiceRow>[] = useMemo(
        () => [
            {
                key: 'number',
                header: 'Number',
                sortable: true,
                sortKey: 'number',
                cell: (row) => (
                    <Link
                        href={route('admin.invoices.show', row.id)}
                        className="font-medium text-primary hover:underline"
                    >
                        {row.number}
                    </Link>
                ),
            },
            {
                key: 'customer',
                header: 'Customer',
                cell: (row) =>
                    row.customer ? (
                        <div>
                            <div className="font-medium">{row.customer.name}</div>
                            <div className="text-xs text-muted-foreground">{row.customer.code}</div>
                        </div>
                    ) : (
                        '—'
                    ),
            },
            {
                key: 'status',
                header: 'Status',
                sortable: true,
                sortKey: 'status',
                cell: (row) => (
                    <StatusBadge
                        status={row.status}
                        label={STATUS_LABEL[row.status] ?? row.status}
                    />
                ),
            },
            {
                key: 'issue_date',
                header: 'Issue Date',
                sortable: true,
                sortKey: 'issue_date',
                cell: (row) => formatDate(row.issue_date),
            },
            {
                key: 'due_date',
                header: 'Due Date',
                sortable: true,
                sortKey: 'due_date',
                cell: (row) => formatDate(row.due_date),
            },
            {
                key: 'total',
                header: 'Total',
                sortable: true,
                sortKey: 'total',
                className: 'text-right tabular-nums',
                cell: (row) => formatMoney(row.total),
            },
            {
                key: 'sisa',
                header: 'Remaining',
                className: 'text-right tabular-nums',
                cell: (row) => formatMoney(row.sisa),
            },
        ],
        [],
    );

    return (
        <AppLayout>
            <Head title="Invoices" />
            <div className="space-y-4 p-4 md:p-6">
                <PageHeader
                    title="Invoices"
                    description="Customer invoices and balances."
                    actions={
                        <div className="flex flex-wrap gap-2">
                            <ExportMenu
                                exportUrl={route('admin.invoices.export')}
                                params={params}
                                canExport={can('billing.export')}
                            />
                            {can('billing.create') && (
                                <>
                                    <Button
                                        type="button"
                                        size="sm"
                                        variant="outline"
                                        onClick={() => setGenerateOpen(true)}
                                    >
                                        Generate Tagihan
                                    </Button>
                                    <Button asChild size="sm">
                                        <Link href={route('admin.invoices.create')}>
                                            <Plus className="size-4" />
                                            New invoice
                                        </Link>
                                    </Button>
                                </>
                            )}
                        </div>
                    }
                />

                <div className="flex flex-col gap-3 rounded-lg border bg-card p-3 md:flex-row md:flex-wrap md:items-end">
                    <div className="min-w-[12rem] flex-1 space-y-1">
                        <label
                            className="text-xs font-medium text-muted-foreground"
                            htmlFor="inv-search"
                        >
                            Search
                        </label>
                        <Input
                            id="inv-search"
                            value={params.search ?? ''}
                            onChange={(e) => set('search', e.target.value)}
                            placeholder="Number or customer…"
                        />
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
                                        {STATUS_LABEL[s] ?? s}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>
                    <div className="space-y-1">
                        <label
                            className="text-xs font-medium text-muted-foreground"
                            htmlFor="inv-from"
                        >
                            From
                        </label>
                        <Input
                            id="inv-from"
                            type="date"
                            value={params.date_from ?? ''}
                            onChange={(e) => set('date_from', e.target.value)}
                        />
                    </div>
                    <div className="space-y-1">
                        <label
                            className="text-xs font-medium text-muted-foreground"
                            htmlFor="inv-to"
                        >
                            To
                        </label>
                        <Input
                            id="inv-to"
                            type="date"
                            value={params.date_to ?? ''}
                            onChange={(e) => set('date_to', e.target.value)}
                        />
                    </div>
                </div>

                <DataTable
                    columns={columns}
                    data={invoices.data}
                    emptyTitle="No invoices"
                    emptyDescription="No invoices match the current filters."
                    sortBy={sortBy}
                    sortDir={sortDir}
                    onSort={onSort}
                    pagination={invoices}
                    onPageChange={(page) => set('page', page)}
                    onPerPageChange={(n) => set('per_page', n)}
                />
                <GenerateDialog open={generateOpen} onClose={() => setGenerateOpen(false)} />
            </div>
        </AppLayout>
    );
}
