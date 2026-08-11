import { Head, Link } from '@inertiajs/react';
import { useMemo } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import { PageHeader } from '@/Components/composite/PageHeader';
import { DataTable, type DataTableColumn } from '@/Components/composite/DataTable';
import { Button } from '@/Components/ui/Button';
import { Input } from '@/Components/ui/Input';
import { useTableQuery } from '@/hooks/useTableQuery';

type StockRow = {
    id: number;
    quantity: number | string;
    reserved_quantity?: number | string;
    available_quantity?: number | string;
    min_quantity?: number | string | null;
    product?: { id: number; name: string; sku?: string | null } | null;
    location?: { id: number; name: string } | null;
    updated_at?: string | null;
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
    stocks: Paginator<StockRow>;
    filters: {
        search?: string;
        location_id?: string;
        sort_by?: string;
        sort_dir?: string;
        per_page?: string | number;
    };
    locations?: { id: number; name: string }[];
};

export default function StocksIndex({ stocks, filters, locations = [] }: Props) {
    const { params, set, sortBy, sortDir, onSort } = useTableQuery(
        {
            search: filters.search ?? '',
            location_id: filters.location_id ?? '',
            sort_by: filters.sort_by ?? '',
            sort_dir: filters.sort_dir ?? '',
            per_page: filters.per_page ?? stocks.per_page,
        },
        { only: ['stocks', 'filters'] },
    );

    const columns: DataTableColumn<StockRow>[] = useMemo(
        () => [
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
                key: 'location',
                header: 'Location',
                cell: (row) => row.location?.name ?? '—',
            },
            {
                key: 'quantity',
                header: 'On hand',
                sortable: true,
                sortKey: 'quantity',
                className: 'text-right tabular-nums',
                cell: (row) => String(row.quantity ?? '—'),
            },
            {
                key: 'reserved_quantity',
                header: 'Reserved',
                sortable: true,
                sortKey: 'reserved_quantity',
                className: 'text-right tabular-nums',
                cell: (row) => String(row.reserved_quantity ?? '—'),
            },
            {
                key: 'available_quantity',
                header: 'Available',
                sortable: true,
                sortKey: 'available_quantity',
                className: 'text-right tabular-nums',
                cell: (row) => String(row.available_quantity ?? '—'),
            },
        ],
        [],
    );

    return (
        <AppLayout>
            <Head title="Stocks" />
            <div className="space-y-4 p-4 md:p-6">
                <PageHeader
                    title="Stocks"
                    description="On-hand balances by product and location."
                    actions={
                        <Button asChild size="sm" variant="outline">
                            <Link href={route('admin.inventory.movements.index')}>Movements</Link>
                        </Button>
                    }
                />

                <div className="flex flex-col gap-3 rounded-lg border bg-card p-3 md:flex-row md:flex-wrap md:items-end">
                    <div className="min-w-[12rem] flex-1 space-y-1">
                        <label
                            className="text-xs font-medium text-muted-foreground"
                            htmlFor="st-search"
                        >
                            Search
                        </label>
                        <Input
                            id="st-search"
                            value={params.search ?? ''}
                            onChange={(e) => set('search', e.target.value)}
                            placeholder="Product or SKU…"
                        />
                    </div>
                </div>

                <DataTable
                    columns={columns}
                    data={stocks.data}
                    emptyTitle="No stock rows"
                    emptyDescription="No stock balances match the current filters."
                    sortBy={sortBy}
                    sortDir={sortDir}
                    onSort={onSort}
                    pagination={stocks}
                    onPageChange={(page) => set('page', page)}
                    onPerPageChange={(n) => set('per_page', n)}
                />
            </div>
        </AppLayout>
    );
}
