import { Head, Link } from '@inertiajs/react';
import { useMemo } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import { PageHeader } from '@/Components/composite/PageHeader';
import { StatusBadge } from '@/Components/composite/StatusBadge';
import { DataTable, type DataTableColumn } from '@/Components/composite/DataTable';
import { Button } from '@/Components/ui/Button';
import { Input } from '@/Components/ui/Input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/Components/ui/Select';
import { useTableQuery } from '@/hooks/useTableQuery';
import { formatDateTime } from '@/lib/format';

type MovementRow = {
    id: number;
    type: string;
    quantity: number | string;
    reference?: string | null;
    notes?: string | null;
    created_at: string | null;
    product?: { id: number; name: string; sku?: string | null } | null;
    from_location?: { id: number; name: string } | null;
    to_location?: { id: number; name: string } | null;
    created_by?: { id: number; name: string } | null;
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
    movements: Paginator<MovementRow>;
    filters: {
        search?: string;
        type?: string;
        sort_by?: string;
        sort_dir?: string;
        per_page?: string | number;
    };
    typeOptions?: string[];
};

export default function MovementsIndex({ movements, filters, typeOptions = [] }: Props) {
    const { params, set, sortBy, sortDir, onSort } = useTableQuery(
        {
            search: filters.search ?? '',
            type: filters.type ?? '',
            sort_by: filters.sort_by ?? '',
            sort_dir: filters.sort_dir ?? '',
            per_page: filters.per_page ?? movements.per_page,
        },
        { only: ['movements', 'filters'] },
    );

    const columns: DataTableColumn<MovementRow>[] = useMemo(
        () => [
            {
                key: 'type',
                header: 'Type',
                sortable: true,
                sortKey: 'type',
                cell: (row) => <StatusBadge status={row.type} />,
            },
            {
                key: 'product',
                header: 'Product',
                cell: (row) =>
                    row.product ? (
                        <div>
                            <div className="font-medium">{row.product.name}</div>
                            {row.product.sku ? (
                                <div className="text-xs text-muted-foreground">
                                    {row.product.sku}
                                </div>
                            ) : null}
                        </div>
                    ) : (
                        '—'
                    ),
            },
            {
                key: 'quantity',
                header: 'Qty',
                sortable: true,
                sortKey: 'quantity',
                className: 'text-right tabular-nums',
                cell: (row) => String(row.quantity ?? '—'),
            },
            {
                key: 'from',
                header: 'From',
                cell: (row) => row.from_location?.name ?? '—',
            },
            {
                key: 'to',
                header: 'To',
                cell: (row) => row.to_location?.name ?? '—',
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
            <Head title="Stock Movements" />
            <div className="space-y-4 p-4 md:p-6">
                <PageHeader
                    title="Stock Movements"
                    description="Inbound, outbound, transfer, and adjustment history."
                    actions={
                        <Button asChild size="sm" variant="outline">
                            <Link href={route('admin.inventory.stocks.index')}>Stocks</Link>
                        </Button>
                    }
                />

                <div className="flex flex-col gap-3 rounded-lg border bg-card p-3 md:flex-row md:flex-wrap md:items-end">
                    <div className="min-w-[12rem] flex-1 space-y-1">
                        <label
                            className="text-xs font-medium text-muted-foreground"
                            htmlFor="mv-search"
                        >
                            Search
                        </label>
                        <Input
                            id="mv-search"
                            value={params.search ?? ''}
                            onChange={(e) => set('search', e.target.value)}
                            placeholder="Product, reference…"
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
                                {(typeOptions.length
                                    ? typeOptions
                                    : ['in', 'out', 'transfer', 'adjust']
                                ).map((s) => (
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
                    data={movements.data}
                    emptyTitle="No movements"
                    emptyDescription="No stock movements match the current filters."
                    sortBy={sortBy}
                    sortDir={sortDir}
                    onSort={onSort}
                    pagination={movements}
                    onPageChange={(page) => set('page', page)}
                    onPerPageChange={(n) => set('per_page', n)}
                />
            </div>
        </AppLayout>
    );
}
