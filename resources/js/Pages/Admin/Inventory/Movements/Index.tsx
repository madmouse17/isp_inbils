import { useState, type FormEvent } from 'react';
import { Head, Link } from '@inertiajs/react';
import { useMemo } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import { PageHeader } from '@/Components/composite/PageHeader';
import { StatusBadge } from '@/Components/composite/StatusBadge';
import { DataTable, type DataTableColumn } from '@/Components/composite/DataTable';
import { Button } from '@/Components/ui/Button';
import { Input } from '@/Components/ui/Input';
import { NativeSelect } from '@/Components/ui';
import { useTableQuery } from '@/hooks/useTableQuery';
import { formatDateTime } from '@/lib/format';
import { ExportMenu } from '@/Components/ui/ExportMenu';
import { toPagination, type PaginationLike } from '@/lib/pagination';

type MovementRow = {
    id: number;
    movement_type: string;
    quantity: number | string;
    reference?: string | null;
    note?: string | null;
    created_at: string | null;
    product?: { id: number; name: string; sku?: string | null } | null;
    from_location?: { id: number; name: string } | null;
    to_location?: { id: number; name: string } | null;
    created_by?: { id: number; name: string } | null;
};

type Paginator<T> = PaginationLike<T>;

type Props = {
    movements: Paginator<MovementRow>;
    filters: {
        search?: string;
        movement_type?: string;
        sort_by?: string;
        sort_dir?: string;
        per_page?: string | number;
        product_id?: string | number;
        location_id?: string | number;
        sort?: string;
        direction?: string;
    };
    typeOptions?: string[];
    can: { export?: boolean };
};

const MOVEMENT_TYPES = [
    'receive',
    'issue',
    'transfer',
    'adjustment',
    'reserve',
    'release',
    'return',
];

export default function MovementsIndex({ movements, filters, can, typeOptions = [] }: Props) {
    const pagination = toPagination(movements);
    const { params, set, setMany, sortBy, sortDir, onSort, processing } = useTableQuery(
        {
            search: filters.search ?? '',
            movement_type: filters.movement_type ?? '',
            product_id: filters.product_id ?? '',
            location_id: filters.location_id ?? '',
            sort_by: filters.sort_by ?? filters.sort ?? '',
            sort_dir: filters.sort_dir ?? filters.direction ?? '',
            per_page: pagination.per_page,
        },
        { only: ['movements', 'filters'] },
    );

    const [type, setType] = useState(filters.movement_type ?? '');

    const applyFilter = (e: FormEvent) => {
        e.preventDefault();
        setMany({ movement_type: type });
    };

    const columns: DataTableColumn<MovementRow>[] = useMemo(
        () => [
            {
                key: 'movement_type',
                header: 'Type',
                sortable: true,
                sortKey: 'movement_type',
                cell: (row) => <StatusBadge status={row.movement_type} />,
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
                key: 'note',
                header: 'Note',
                cell: (row) => row.note ?? '—',
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
                        <div className="flex flex-wrap gap-2">
                            <ExportMenu
                                exportUrl={route('admin.stock-movements.export')}
                                params={params}
                                canExport={can.export ?? false}
                                formats={['csv', 'pdf']}
                            />
                            <Button asChild size="sm" variant="outline">
                                <Link href={route('admin.stocks.index')}>Stocks</Link>
                            </Button>
                        </div>
                    }
                />

                <form
                    onSubmit={applyFilter}
                    className="flex flex-col gap-3 rounded-lg border bg-card p-3 md:flex-row md:flex-wrap md:items-end"
                >
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
                            placeholder="Product, reference..."
                        />
                    </div>
                    <div className="space-y-1">
                        <NativeSelect
                            label="Type"
                            value={type}
                            onChange={(e) => setType(e.target.value)}
                            options={[
                                { value: '', label: 'All' },
                                ...(typeOptions.length ? typeOptions : MOVEMENT_TYPES).map((t) => ({
                                    value: t,
                                    label: t,
                                })),
                            ]}
                            className="w-[10rem]"
                        />
                    </div>
                    <div className="self-end">
                        <Button type="submit" variant="secondary">
                            Filter
                        </Button>
                    </div>
                </form>

                <DataTable
                    columns={columns}
                    data={movements.data}
                    emptyTitle="No movements"
                    emptyDescription="No stock movements match the current filters."
                    loading={processing}
                    sortBy={sortBy}
                    sortDir={sortDir}
                    onSort={onSort}
                    pagination={pagination}
                    onPageChange={(page) => set('page', page)}
                    onPerPageChange={(n) => set('per_page', n)}
                />
            </div>
        </AppLayout>
    );
}
