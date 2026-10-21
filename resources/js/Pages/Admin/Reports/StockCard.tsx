import type { FormEvent } from 'react';
import { useState } from 'react';
import { Link } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import {
    DataTable,
    FormulaVersionLabel,
    MetricDefinitionNote,
    PageHeader,
    RemoteSearchSelect,
    type DataTableColumn,
    type RemoteOption,
} from '@/Components/composite';
import {
    Button,
    Card,
    CardContent,
    CardHeader,
    CardTitle,
    DateRangePicker,
    EnumBadge,
    type DateRange,
} from '@/Components/ui';
import { useCompany } from '@/hooks/useCompany';
import { useServerTable } from '@/hooks/useServerTable';
import { formatDate, formatDateTime } from '@/lib/format';
import { toPagination, type PaginationLike } from '@/lib/pagination';
import { STOCK_MOVEMENT_TYPE } from '@/lib/statuses';
import type { RecentStockProduct, ReportMeta } from '@/types';
import { ReportExportMenu } from './components/ReportExportMenu';

interface Movement {
    id: string;
    movement_type: string;
    quantity: string;
    balance_after: string;
    from_location?: string | null;
    to_location?: string | null;
    note?: string | null;
    reference?: { label: string; url: string | null } | null;
    created_at: string;
}

interface Props extends Record<string, unknown> {
    movements?: PaginationLike<Movement> | null;
    filters: {
        product_id?: string;
        location_id?: string;
        date_from?: string;
        date_to?: string;
        sort_by?: string;
        sort_dir?: 'asc' | 'desc';
        per_page?: number;
    };
    productOptions?: RemoteOption[];
    selectedProduct?: RemoteOption | null;
    locationOptions?: RemoteOption[];
    selectedLocation?: RemoteOption | null;
    meta: ReportMeta;
    can: { export: boolean };
    links?: { stocks: string | null; movements: string | null } | null;
    /** The products whose stock moved last; present only while no product is chosen. */
    recentProducts?: RecentStockProduct[] | null;
}

export default function StockCard({
    movements,
    filters,
    productOptions,
    selectedProduct,
    locationOptions,
    selectedLocation,
    meta,
    can,
    links,
    recentProducts,
}: Props) {
    const company = useCompany();
    const pagination = movements ? toPagination(movements) : null;
    const table = useServerTable({
        url: route('admin.reports.stock-card'),
        filters: {
            product_id: filters.product_id,
            location_id: filters.location_id,
            date_from: filters.date_from,
            date_to: filters.date_to,
            sort: filters.sort_by,
            direction: filters.sort_dir,
            per_page: pagination?.per_page ?? filters.per_page ?? 10,
        },
        only: ['movements', 'filters', 'selectedProduct', 'selectedLocation'],
    });
    const [productId, setProductId] = useState(filters.product_id ?? '');
    const [locationId, setLocationId] = useState(filters.location_id ?? '');
    const [range, setRange] = useState<DateRange>({
        from: filters.date_from || null,
        to: filters.date_to || null,
    });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        table.visit({
            product_id: productId,
            location_id: locationId,
            date_from: range.from ?? '',
            date_to: range.to ?? '',
            page: 1,
        });
    };

    const columns: DataTableColumn<Movement>[] = [
        {
            key: 'movement_type',
            header: 'Jenis',
            cell: (row) => <EnumBadge map={STOCK_MOVEMENT_TYPE} value={row.movement_type} />,
        },
        { key: 'quantity', header: 'Jumlah', sortable: true },
        { key: 'balance_after', header: 'Saldo', sortable: true },
        { key: 'from_location', header: 'Dari', cell: (row) => row.from_location ?? '-' },
        { key: 'to_location', header: 'Ke', cell: (row) => row.to_location ?? '-' },
        { key: 'note', header: 'Catatan', cell: (row) => row.note ?? '-' },
        {
            key: 'reference',
            header: 'Referensi',
            cell: (row) =>
                row.reference?.url ? (
                    <Link
                        href={row.reference.url}
                        className="text-primary-text underline-offset-2 hover:underline"
                    >
                        {row.reference.label}
                    </Link>
                ) : (
                    (row.reference?.label ?? '-')
                ),
        },
        {
            key: 'created_at',
            header: 'Tanggal',
            sortable: true,
            className: 'text-sm',
            cell: (row) => formatDateTime(row.created_at, company),
        },
    ];

    return (
        <AdminLayout title="Kartu Stok">
            <div className="space-y-6">
                <PageHeader
                    title="Kartu Stok"
                    subtitle="Riwayat pergerakan stok satu barang, boleh dibatasi per lokasi dan periode."
                    actions={
                        <>
                            <FormulaVersionLabel version={meta.formula_version} />
                            {links?.stocks && (
                                <Button asChild variant="outline" size="sm">
                                    <Link href={links.stocks}>Lihat stok</Link>
                                </Button>
                            )}
                            {links?.movements && (
                                <Button asChild variant="outline" size="sm">
                                    <Link href={links.movements}>Semua pergerakan</Link>
                                </Button>
                            )}
                            <ReportExportMenu
                                url={route('admin.reports.stock-card.export')}
                                params={{
                                    product_id: filters.product_id,
                                    location_id: filters.location_id,
                                    date_from: filters.date_from,
                                    date_to: filters.date_to,
                                    sort_by: filters.sort_by,
                                    sort_dir: filters.sort_dir,
                                }}
                                canExport={can.export && Boolean(filters.product_id)}
                            />
                        </>
                    }
                />
                <Card>
                    <CardContent className="space-y-4 pt-6">
                        <form onSubmit={submit} className="flex flex-wrap gap-2">
                            <div className="w-full sm:w-72">
                                <RemoteSearchSelect
                                    field="product"
                                    label="Barang"
                                    placeholder="Cari nama atau SKU..."
                                    options={productOptions}
                                    selected={selectedProduct}
                                    value={productId}
                                    onChange={setProductId}
                                    required
                                />
                            </div>
                            <div className="w-full sm:w-64">
                                <RemoteSearchSelect
                                    field="location"
                                    label="Lokasi"
                                    placeholder="Semua lokasi"
                                    options={locationOptions}
                                    selected={selectedLocation}
                                    value={locationId}
                                    onChange={setLocationId}
                                />
                            </div>
                            <div className="w-full sm:w-96">
                                <DateRangePicker
                                    id="stock-card-range"
                                    label="Periode"
                                    value={range}
                                    onChange={setRange}
                                />
                            </div>
                            <div className="self-end">
                                <Button type="submit" disabled={table.visiting || productId === ''}>
                                    Tampilkan
                                </Button>
                            </div>
                        </form>
                        {!filters.product_id && (
                            <div className="space-y-4">
                                <p className="text-sm text-muted-foreground">
                                    Pilih barang lalu tekan Tampilkan untuk melihat pergerakan stok.
                                </p>
                                {recentProducts && recentProducts.length > 0 && (
                                    <section
                                        aria-labelledby="stock-card-recent"
                                        className="space-y-2"
                                    >
                                        <h2 id="stock-card-recent" className="text-sm font-medium">
                                            Barang terakhir bergerak
                                        </h2>
                                        <ul className="grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
                                            {recentProducts.map((product) => (
                                                <li key={product.value}>
                                                    <Link
                                                        href={route('admin.reports.stock-card', {
                                                            product_id: product.value,
                                                        })}
                                                        className="block min-h-11 rounded-md border border-border px-3 py-2 hover:bg-muted/50 focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background focus-visible:outline-hidden"
                                                    >
                                                        <span className="block truncate text-sm font-medium">
                                                            {product.label}
                                                        </span>
                                                        <span className="block truncate text-xs text-muted-foreground">
                                                            {product.description
                                                                ? `${product.description} · `
                                                                : ''}
                                                            {formatDate(
                                                                product.last_moved_at,
                                                                company,
                                                            )}
                                                        </span>
                                                    </Link>
                                                </li>
                                            ))}
                                        </ul>
                                    </section>
                                )}
                            </div>
                        )}
                        {filters.product_id && movements && pagination && (
                            <DataTable
                                columns={columns}
                                data={movements.data}
                                pagination={pagination}
                                rowKey="id"
                                sortBy={table.sortBy}
                                sortDir={table.sortDir}
                                onSort={table.onSort}
                                onPageChange={(page) => table.setPage(page)}
                                onPerPageChange={(perPage) => table.setPerPage(perPage)}
                                loading={table.processing}
                                emptyTitle="Tidak ada pergerakan stok"
                                emptyDescription="Tidak ada pergerakan untuk barang dan rentang tanggal ini. Ubah filter lalu tampilkan lagi."
                            />
                        )}
                        {!movements && table.processing && (
                            <p role="status" aria-live="polite">
                                Memuat pergerakan stok...
                            </p>
                        )}
                    </CardContent>
                </Card>
                <Card>
                    <CardHeader>
                        <CardTitle>Definisi</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-6">
                        {(['movements', 'quantity', 'balance_after'] as const).map((key) => (
                            <div key={key} className="min-w-0">
                                <p className="font-medium">{meta.metrics[key]?.label ?? key}</p>
                                <MetricDefinitionNote
                                    definition={meta.metrics[key]}
                                    className="mt-2 border-t-0 pt-0"
                                />
                            </div>
                        ))}
                    </CardContent>
                </Card>
            </div>
        </AdminLayout>
    );
}
