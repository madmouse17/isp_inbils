import { useMemo } from 'react';
import { Link } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import { PageHeader } from '@/Components/composite/PageHeader';
import { DataTable, type DataTableColumn } from '@/Components/composite/DataTable';
import { NativeSelect } from '@/Components/composite/NativeSelect';
import { RemoteSearchSelect, type RemoteOption } from '@/Components/composite/RemoteSearchSelect';
import { Button } from '@/Components/ui/Button';
import { EmptyCell } from '@/Components/ui/EmptyCell';
import { EnumBadge } from '@/Components/ui/EnumBadge';
import { Input } from '@/Components/ui/Input';
import { ExportMenu } from '@/Components/ui/ExportMenu';
import { useCompany } from '@/hooks/useCompany';
import { useTableQuery } from '@/hooks/useTableQuery';
import { formatDateTime, formatNumber, formatRupiah } from '@/lib/format';
import { toPagination, type PaginationLike } from '@/lib/pagination';
import { optionsOf, STOCK_MOVEMENT_TYPE } from '@/lib/statuses';
import type { StockMovement as MovementRow } from '@/types/inventory';

type Paginator<T> = PaginationLike<T>;

type Props = {
    movements: Paginator<MovementRow>;
    /** Answers of the server-side search; only present after the first search. */
    productOptions?: RemoteOption[];
    locationOptions?: RemoteOption[];
    /** The options behind `filters.product_id` and `filters.location_id`, so the filters read before any search. */
    selectedProduct: RemoteOption | null;
    selectedLocation: RemoteOption | null;
    filters: {
        search?: string;
        movement_type?: string;
        sort_by?: string;
        sort_dir?: string;
        per_page?: string | number;
        product_id?: string;
        location_id?: string;
        sort?: string;
        direction?: string;
    };
    can: { export?: boolean };
};

const TYPE_OPTIONS = [{ value: '', label: 'Semua jenis' }, ...optionsOf(STOCK_MOVEMENT_TYPE)];

export default function MovementsIndex({
    movements,
    productOptions,
    locationOptions,
    selectedProduct,
    selectedLocation,
    filters,
    can,
}: Props) {
    const company = useCompany();
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
        { only: ['movements', 'filters', 'selectedProduct', 'selectedLocation'] },
    );
    const hasFilters = Boolean(
        params.search || params.movement_type || params.product_id || params.location_id,
    );

    const columns: DataTableColumn<MovementRow>[] = useMemo(
        () => [
            {
                key: 'movement_type',
                header: 'Jenis',
                sortable: true,
                sortKey: 'movement_type',
                cell: (row) => <EnumBadge map={STOCK_MOVEMENT_TYPE} value={row.movement_type} />,
            },
            {
                key: 'product',
                header: 'Barang',
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
                        <EmptyCell />
                    ),
            },
            {
                key: 'quantity',
                header: 'Jumlah',
                sortable: true,
                sortKey: 'quantity',
                className: 'text-right tabular-nums',
                cell: (row) => formatNumber(row.quantity),
            },
            {
                key: 'balance_after',
                header: 'Saldo setelah',
                className: 'text-right tabular-nums',
                cell: (row) => formatNumber(row.balance_after),
            },
            {
                key: 'from',
                header: 'Dari',
                cell: (row) => row.from_location?.name ?? <EmptyCell />,
            },
            {
                key: 'to',
                header: 'Ke',
                cell: (row) => row.to_location?.name ?? <EmptyCell />,
            },
            {
                key: 'actor',
                header: 'Oleh',
                cell: (row) => row.actor?.name ?? <EmptyCell />,
            },
            {
                key: 'note',
                header: 'Catatan',
                cell: (row) => {
                    const receipt = [
                        row.supplier_name ? `Pemasok: ${row.supplier_name}` : null,
                        row.purchase_reference ? `No. faktur: ${row.purchase_reference}` : null,
                        row.unit_cost !== null && row.unit_cost !== undefined
                            ? `Harga satuan: ${formatRupiah(row.unit_cost, company)}`
                            : null,
                    ].filter((line): line is string => line !== null);

                    if (!row.note && receipt.length === 0) return <EmptyCell />;

                    return (
                        <div>
                            {row.note ? <div>{row.note}</div> : null}
                            {receipt.map((line) => (
                                <div key={line} className="text-xs text-muted-foreground">
                                    {line}
                                </div>
                            ))}
                        </div>
                    );
                },
            },
            {
                key: 'created_at',
                header: 'Waktu',
                sortable: true,
                sortKey: 'created_at',
                cell: (row) => formatDateTime(row.created_at, company),
            },
        ],
        [company],
    );

    return (
        <AdminLayout title="Mutasi Stok">
            <div className="space-y-4">
                <PageHeader
                    title="Mutasi Stok"
                    description="Riwayat barang masuk, keluar, transfer, dan penyesuaian. Mutasi yang sudah tercatat tidak bisa diubah."
                    actions={
                        <div className="flex flex-wrap gap-2">
                            <ExportMenu
                                exportUrl={route('admin.stock-movements.export')}
                                params={params}
                                canExport={can.export ?? false}
                                formats={['csv', 'pdf']}
                            />
                            <Button asChild size="sm" variant="outline">
                                <Link href={route('admin.stocks.index')}>Stok</Link>
                            </Button>
                        </div>
                    }
                />

                <div className="flex flex-col gap-3 rounded-lg border bg-card p-3 md:flex-row md:flex-wrap md:items-end">
                    <div className="min-w-48 flex-1">
                        <Input
                            id="mv-search"
                            label="Cari mutasi"
                            value={params.search ?? ''}
                            onChange={(e) => set('search', e.target.value)}
                            placeholder="Barang, SKU, lokasi, atau catatan"
                        />
                    </div>
                    <NativeSelect
                        id="mv-type"
                        label="Jenis"
                        value={params.movement_type ?? ''}
                        onChange={(e) => set('movement_type', e.target.value)}
                        options={TYPE_OPTIONS}
                        wrapperClassName="md:w-44"
                    />
                    <div className="w-full md:w-64">
                        <RemoteSearchSelect
                            id="mv-product"
                            field="product"
                            label="Barang"
                            placeholder="Semua barang"
                            options={productOptions}
                            selected={selectedProduct}
                            value={params.product_id ?? ''}
                            onChange={(value) => set('product_id', value)}
                        />
                    </div>
                    <div className="w-full md:w-64">
                        <RemoteSearchSelect
                            id="mv-location"
                            field="location"
                            label="Lokasi"
                            placeholder="Semua lokasi"
                            options={locationOptions}
                            selected={selectedLocation}
                            value={params.location_id ?? ''}
                            onChange={(value) => set('location_id', value)}
                        />
                    </div>
                </div>

                <DataTable
                    columns={columns}
                    data={movements.data}
                    itemLabel="mutasi"
                    emptyTitle="Belum ada mutasi stok"
                    emptyDescription="Mutasi tercatat setiap kali stok masuk, keluar, dipindah, atau disesuaikan."
                    hasFilters={hasFilters}
                    onResetFilters={() =>
                        setMany({ search: '', movement_type: '', product_id: '', location_id: '' })
                    }
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
