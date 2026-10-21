import { ExportMenu } from '@/Components/ui/ExportMenu';
import { PageHeader } from '@/Components/composite/PageHeader';
import { DataTable, type DataTableColumn } from '@/Components/composite/DataTable';
import { RemoteSearchSelect, type RemoteOption } from '@/Components/composite/RemoteSearchSelect';
import { Badge } from '@/Components/ui/Badge';
import { Button } from '@/Components/ui/Button';
import { EmptyCell } from '@/Components/ui/EmptyCell';
import { EnumBadge } from '@/Components/ui/EnumBadge';
import { Input } from '@/Components/ui/Input';
import { Modal } from '@/Components/ui/Modal';
import { Textarea } from '@/Components/ui/Textarea';
import AdminLayout from '@/Layouts/AdminLayout';
import { useTableQuery } from '@/hooks/useTableQuery';
import { toPagination, type PaginationLike } from '@/lib/pagination';
import { formatNumber } from '@/lib/format';
import { STOCK_ADJUSTMENT_STATUS } from '@/lib/statuses';
import type { StockLookup } from '@/types/inventory';
import { router, useForm, usePage } from '@inertiajs/react';
import { useEffect, useMemo, useState, type FormEvent } from 'react';

type StockRow = {
    id: string;
    product_id?: string | null;
    product?: { id: string; name: string; sku?: string | null } | null;
    location_id?: string | null;
    quantity: number | string;
    reserved_quantity?: number | string | null;
    available?: number | string | null;
    location?: { id: string; name: string; code?: string | null } | null;
};

type AdjustmentRequestRow = {
    id: string;
    status: string;
    old_quantity: number | string;
    new_quantity: number | string;
    delta: number | string;
    reason: string;
    product?: { id: string; name: string } | null;
    location?: { id: string; name: string; code?: string | null } | null;
    requester?: { id: string; name: string } | null;
    can: { approve: boolean; reject: boolean };
};

type Paginator<T> = PaginationLike<T>;

type MaybeWrapped<T> = T[] | { data?: T[] };

type Props = {
    stocks: Paginator<StockRow>;
    /** Answers of the server-side search; only present after the first search. */
    productOptions?: RemoteOption[];
    locationOptions?: RemoteOption[];
    /** The options behind `filters.product_id` and `filters.location_id`, so the filters read before any search. */
    selectedProduct: RemoteOption | null;
    selectedLocation: RemoteOption | null;
    adjustmentRequests: MaybeWrapped<AdjustmentRequestRow>;
    filters: {
        search?: string;
        location_id?: string;
        product_id?: string;
        low_stock?: string | number | boolean;
        sort_by?: string;
        sort_dir?: string;
        sort?: string;
        direction?: string;
        per_page?: number;
    };
    can: {
        export?: boolean;
        receive?: boolean;
        transfer?: boolean;
        adjust_request?: boolean;
        adjust_approve?: boolean;
    };
    [key: string]: unknown;
};

type ReceiveFormData = {
    product_id: string;
    location_id: string;
    quantity: string;
    supplier_name: string;
    purchase_reference: string;
    unit_cost: string;
    note: string;
};

type TransferFormData = {
    product_id: string;
    from_location_id: string;
    to_location_id: string;
    quantity: string;
    note: string;
};

type AdjustFormData = {
    product_id: string;
    location_id: string;
    new_quantity: string;
    note: string;
};

type DecisionState = { row: AdjustmentRequestRow; mode: 'approve' | 'reject' };

const locationLabel = (location?: { name: string; code?: string | null } | null) =>
    location ? (location.code ? `${location.code} - ${location.name}` : location.name) : '-';

const signed = (value: number | string) => {
    const number = Number(value);
    return `${number > 0 ? '+' : ''}${formatNumber(number)}`;
};

const quantityCell = (value?: number | string | null) =>
    value === null || value === undefined ? <EmptyCell /> : formatNumber(value);

/**
 * What the system holds of a product at a place. The server answers a partial reload of `stockLookup` once both are chosen,
 * so the dialogs show it without the page loading every stock. The answer is kept here, keyed by what it was asked for,
 * so a dialog never shows the figure of the pair chosen before.
 */
function useStockLookup(productId: string, locationId: string, enabled: boolean) {
    const key = `${productId}:${locationId}`;
    const wanted = enabled && productId !== '' && locationId !== '';
    const [answer, setAnswer] = useState<{ key: string; stock: StockLookup | null } | null>(null);

    useEffect(() => {
        if (!wanted) return;
        let active = true;

        router.reload({
            only: ['stockLookup'],
            data: { lookup_product_id: productId, lookup_location_id: locationId },
            preserveUrl: true,
            onSuccess: (page) => {
                if (active) {
                    setAnswer({
                        key: `${productId}:${locationId}`,
                        stock: (page.props.stockLookup as StockLookup | null | undefined) ?? null,
                    });
                }
            },
        });

        return () => {
            active = false;
        };
    }, [wanted, productId, locationId]);

    if (!wanted) return { loading: false, stock: null };
    return answer?.key === key
        ? { loading: false, stock: answer.stock }
        : { loading: true, stock: null };
}

/** The figure a dialog shows under a chosen product and place: nothing before both are chosen. */
function StockFigure({
    lookup,
    label,
    pick,
}: {
    lookup: { loading: boolean; stock: StockLookup | null };
    label: string;
    pick: 'quantity' | 'available';
}) {
    if (!lookup.loading && !lookup.stock) return null;
    const { stock } = lookup;
    const reserved = stock && pick === 'quantity' ? Number(stock.reserved_quantity) : 0;

    return (
        <p className="text-sm text-muted-foreground" aria-live="polite">
            {stock
                ? `${label}: ${formatNumber(stock[pick])} ${stock.unit ?? ''}`.trim()
                : 'Memuat stok...'}
            {reserved > 0 ? ` (${formatNumber(reserved)} dicadangkan)` : ''}
        </p>
    );
}

export default function StockIndex({
    stocks,
    productOptions,
    locationOptions,
    selectedProduct,
    selectedLocation,
    adjustmentRequests,
    filters,
    can,
}: Props) {
    const { errors } = usePage().props;
    const receiveErrors = errors as Partial<Record<keyof ReceiveFormData, string>>;
    const canReceive = can.receive ?? false;
    const pagination = toPagination(stocks);
    const { params, set, setMany, sortBy, sortDir, onSort, processing } = useTableQuery(
        {
            search: filters.search ?? '',
            location_id: filters.location_id ?? '',
            product_id: filters.product_id ?? '',
            low_stock: String(filters.low_stock ?? ''),
            sort_by: filters.sort_by ?? filters.sort ?? '',
            sort_dir: filters.sort_dir ?? filters.direction ?? '',
            per_page: pagination.per_page,
        },
        { only: ['stocks', 'filters', 'selectedProduct', 'selectedLocation'] },
    );
    const hasFilters = Boolean(
        params.search || params.location_id || params.product_id || params.low_stock,
    );

    const [receiveOpen, setReceiveOpen] = useState(false);
    const [formError, setFormError] = useState<string | null>(null);

    const form = useForm<ReceiveFormData>({
        product_id: '',
        location_id: '',
        quantity: '1',
        supplier_name: '',
        purchase_reference: '',
        unit_cost: '',
        note: '',
    });

    const openReceive = () => {
        form.reset();
        form.clearErrors();
        setFormError(null);
        setReceiveOpen(true);
    };

    const closeReceive = () => {
        setReceiveOpen(false);
        form.reset();
        form.clearErrors();
        setFormError(null);
    };

    const submit = (e: FormEvent) => {
        e.preventDefault();
        const quantity = Number(form.data.quantity);
        if (!form.data.product_id || !form.data.location_id || !(quantity > 0)) {
            setFormError('Barang, lokasi, dan jumlah (lebih dari 0) wajib diisi.');
            return;
        }
        setFormError(null);
        form.transform((data) => ({
            product_id: data.product_id,
            location_id: data.location_id,
            quantity,
            supplier_name: data.supplier_name.trim() || null,
            purchase_reference: data.purchase_reference.trim() || null,
            // As typed, never through a number: the server keeps every digit of a price.
            unit_cost: data.unit_cost.trim() || null,
            note: data.note || null,
        }));
        form.post(route('admin.stocks.receive'), {
            preserveScroll: true,
            onSuccess: () => closeReceive(),
        });
    };

    const canTransfer = can.transfer ?? false;
    const [transferOpen, setTransferOpen] = useState(false);
    const [transferError, setTransferError] = useState<string | null>(null);
    const transferForm = useForm<TransferFormData>({
        product_id: '',
        from_location_id: '',
        to_location_id: '',
        quantity: '1',
        note: '',
    });
    const transferSource = useStockLookup(
        transferForm.data.product_id,
        transferForm.data.from_location_id,
        canTransfer && transferOpen,
    );

    const openTransfer = () => {
        transferForm.reset();
        transferForm.clearErrors();
        setTransferError(null);
        setTransferOpen(true);
    };

    const closeTransfer = () => {
        setTransferOpen(false);
        transferForm.reset();
        transferForm.clearErrors();
        setTransferError(null);
    };

    const submitTransfer = (e: FormEvent) => {
        e.preventDefault();
        const { product_id, from_location_id, to_location_id } = transferForm.data;
        const quantity = Number(transferForm.data.quantity);
        if (!product_id || !from_location_id || !to_location_id || !(quantity > 0)) {
            setTransferError('Dari, ke, barang, dan jumlah (lebih dari 0) wajib diisi.');
            return;
        }
        if (from_location_id === to_location_id) {
            setTransferError('Lokasi tujuan harus berbeda dari lokasi asal.');
            return;
        }
        setTransferError(null);
        transferForm.transform((data) => ({
            ...data,
            quantity,
            note: data.note || null,
        }));
        transferForm.post(route('admin.stocks.transfer'), {
            preserveScroll: true,
            onSuccess: () => closeTransfer(),
        });
    };

    const canAdjustRequest = can.adjust_request ?? false;
    const [adjustOpen, setAdjustOpen] = useState(false);
    const adjustForm = useForm<AdjustFormData>({
        product_id: '',
        location_id: '',
        new_quantity: '',
        note: '',
    });
    const adjustStock = useStockLookup(
        adjustForm.data.product_id,
        adjustForm.data.location_id,
        canAdjustRequest && adjustOpen,
    );

    const openAdjust = () => {
        adjustForm.reset();
        adjustForm.clearErrors();
        setAdjustOpen(true);
    };

    const closeAdjust = () => {
        setAdjustOpen(false);
        adjustForm.reset();
        adjustForm.clearErrors();
    };

    const submitAdjust = (e: FormEvent) => {
        e.preventDefault();
        adjustForm.transform((data) => ({ ...data, new_quantity: Number(data.new_quantity) }));
        adjustForm.post(route('admin.stocks.adjust'), {
            preserveScroll: true,
            onSuccess: () => closeAdjust(),
        });
    };

    const [decision, setDecision] = useState<DecisionState | null>(null);
    const decisionForm = useForm<{ rejection_reason: string }>({ rejection_reason: '' });
    const decisionError = (decisionForm.errors as Record<string, string | undefined>).error;

    const openDecision = (row: AdjustmentRequestRow, mode: DecisionState['mode']) => {
        decisionForm.reset();
        decisionForm.clearErrors();
        setDecision({ row, mode });
    };

    const closeDecision = () => {
        setDecision(null);
        decisionForm.reset();
        decisionForm.clearErrors();
    };

    const submitDecision = (e: FormEvent) => {
        e.preventDefault();
        if (!decision) return;
        const { row, mode } = decision;
        decisionForm.transform((data) => (mode === 'approve' ? {} : data));
        decisionForm.post(
            route(
                mode === 'approve'
                    ? 'admin.stocks.adjustments.approve'
                    : 'admin.stocks.adjustments.reject',
                row.id,
            ),
            { preserveScroll: true, onSuccess: () => closeDecision() },
        );
    };

    const pendingRows = useMemo(
        () =>
            Array.isArray(adjustmentRequests)
                ? adjustmentRequests
                : (adjustmentRequests?.data ?? []),
        [adjustmentRequests],
    );

    const columns: DataTableColumn<StockRow>[] = useMemo(
        () => [
            {
                key: 'product',
                header: 'Barang',
                cell: (row) =>
                    row.product ? (
                        <div>
                            <span className="font-medium">{row.product.name}</span>
                            {row.product.sku ? (
                                <span className="block text-xs text-muted-foreground">
                                    {row.product.sku}
                                </span>
                            ) : null}
                        </div>
                    ) : (
                        <EmptyCell />
                    ),
            },
            {
                key: 'location',
                header: 'Lokasi',
                cell: (row) => (row.location ? locationLabel(row.location) : <EmptyCell />),
            },
            {
                key: 'quantity',
                header: 'Jumlah',
                sortable: true,
                className: 'text-right tabular-nums',
                cell: (row) => quantityCell(row.quantity),
            },
            {
                key: 'reserved_quantity',
                header: 'Dicadangkan',
                sortable: true,
                className: 'text-right tabular-nums',
                cell: (row) => quantityCell(row.reserved_quantity),
            },
            {
                key: 'available',
                header: 'Tersedia',
                className: 'text-right tabular-nums',
                cell: (row) => quantityCell(row.available),
            },
        ],
        [],
    );

    const adjustmentColumns: DataTableColumn<AdjustmentRequestRow>[] = [
        {
            key: 'product',
            header: 'Barang',
            cell: (row) => <span className="font-medium">{row.product?.name ?? '-'}</span>,
        },
        { key: 'location', header: 'Lokasi', cell: (row) => locationLabel(row.location) },
        {
            key: 'quantity',
            header: 'Stok',
            className: 'text-right tabular-nums',
            cell: (row) => `${formatNumber(row.old_quantity)} → ${formatNumber(row.new_quantity)}`,
        },
        {
            key: 'delta',
            header: 'Selisih',
            className: 'text-right',
            cell: (row) => (
                <Badge variant={Number(row.delta) < 0 ? 'warning' : 'info'}>
                    {signed(row.delta)}
                </Badge>
            ),
        },
        { key: 'reason', header: 'Alasan', cell: (row) => row.reason },
        { key: 'requester', header: 'Pemohon', cell: (row) => row.requester?.name ?? '-' },
        {
            key: 'status',
            header: 'Status',
            cell: (row) => <EnumBadge map={STOCK_ADJUSTMENT_STATUS} value={row.status} />,
        },
        {
            key: 'actions',
            header: 'Aksi',
            cell: (row) =>
                row.can.approve || row.can.reject ? (
                    <div className="flex gap-2">
                        {row.can.approve && (
                            <Button
                                type="button"
                                size="sm"
                                onClick={() => openDecision(row, 'approve')}
                                aria-haspopup="dialog"
                            >
                                Setujui
                            </Button>
                        )}
                        {row.can.reject && (
                            <Button
                                type="button"
                                size="sm"
                                variant="destructive-outline"
                                onClick={() => openDecision(row, 'reject')}
                                aria-haspopup="dialog"
                            >
                                Tolak
                            </Button>
                        )}
                    </div>
                ) : (
                    <EmptyCell />
                ),
        },
    ];

    return (
        <AdminLayout title="Stok">
            <div className="space-y-4">
                <PageHeader
                    title="Stok"
                    description="Jumlah setiap barang di setiap lokasi, dan berapa yang masih tersedia."
                    actions={
                        <div className="flex flex-wrap gap-2">
                            <ExportMenu
                                exportUrl={route('admin.stocks.export')}
                                params={params}
                                canExport={can.export ?? false}
                                formats={['csv', 'pdf']}
                            />
                            {canReceive && (
                                <Button
                                    type="button"
                                    size="sm"
                                    onClick={openReceive}
                                    aria-haspopup="dialog"
                                >
                                    Terima Barang
                                </Button>
                            )}
                            {canTransfer && (
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="outline"
                                    onClick={openTransfer}
                                    aria-haspopup="dialog"
                                >
                                    Pindahkan stok
                                </Button>
                            )}
                            {canAdjustRequest && (
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="outline"
                                    onClick={openAdjust}
                                    aria-haspopup="dialog"
                                >
                                    Ajukan Penyesuaian
                                </Button>
                            )}
                        </div>
                    }
                />

                {pendingRows.length > 0 && (
                    <section aria-labelledby="adjustment-requests-heading" className="space-y-3">
                        <div>
                            <h2
                                id="adjustment-requests-heading"
                                className="text-base font-semibold"
                            >
                                Penyesuaian stok menunggu persetujuan
                            </h2>
                            <p className="text-sm text-muted-foreground">
                                Perubahan stok baru berlaku setelah disetujui orang lain, bukan
                                pemohon.
                            </p>
                        </div>
                        <DataTable columns={adjustmentColumns} data={pendingRows} />
                    </section>
                )}

                <div className="flex flex-col gap-3 rounded-lg border bg-card p-3 md:flex-row md:flex-wrap md:items-end">
                    <div className="min-w-48 flex-1">
                        <Input
                            id="stock-search"
                            label="Cari stok"
                            value={params.search ?? ''}
                            onChange={(e) => set('search', e.target.value)}
                            placeholder="Barang, SKU, atau lokasi"
                        />
                    </div>
                    <div className="w-full md:w-64">
                        <RemoteSearchSelect
                            id="stock-location"
                            field="location"
                            label="Lokasi"
                            placeholder="Semua lokasi"
                            options={locationOptions}
                            selected={selectedLocation}
                            value={params.location_id ?? ''}
                            onChange={(value) => set('location_id', value)}
                        />
                    </div>
                    <div className="w-full md:w-64">
                        <RemoteSearchSelect
                            id="stock-product"
                            field="product"
                            label="Barang"
                            placeholder="Semua barang"
                            options={productOptions}
                            selected={selectedProduct}
                            value={params.product_id ?? ''}
                            onChange={(value) => set('product_id', value)}
                        />
                    </div>
                </div>

                <DataTable
                    columns={columns}
                    data={stocks.data}
                    itemLabel="stok"
                    emptyTitle="Belum ada stok"
                    emptyDescription="Stok muncul setelah barang diterima di sebuah lokasi."
                    emptyAction={
                        canReceive ? (
                            <Button type="button" onClick={openReceive} aria-haspopup="dialog">
                                Terima Barang
                            </Button>
                        ) : undefined
                    }
                    hasFilters={hasFilters}
                    onResetFilters={() =>
                        setMany({ search: '', location_id: '', product_id: '', low_stock: '' })
                    }
                    loading={processing}
                    pagination={pagination}
                    sortBy={sortBy}
                    sortDir={sortDir}
                    onSort={onSort}
                    onPerPageChange={(size) => set('per_page', size)}
                    onPageChange={(page) => set('page', page)}
                />
            </div>

            <Modal
                open={canReceive && receiveOpen}
                onClose={closeReceive}
                title="Terima Barang"
                size="lg"
            >
                <form onSubmit={submit} className="space-y-4">
                    <RemoteSearchSelect
                        id="receive-product"
                        field="product"
                        label="Barang"
                        placeholder="Cari nama atau SKU"
                        required
                        options={productOptions}
                        value={form.data.product_id}
                        onChange={(value) => form.setData('product_id', value)}
                        error={receiveErrors.product_id}
                    />
                    <RemoteSearchSelect
                        id="receive-location"
                        field="location"
                        label="Lokasi"
                        placeholder="Cari kode atau nama lokasi"
                        required
                        options={locationOptions}
                        value={form.data.location_id}
                        onChange={(value) => form.setData('location_id', value)}
                        error={receiveErrors.location_id}
                    />
                    <Input
                        id="receive-quantity"
                        type="number"
                        min={0.01}
                        step="any"
                        label="Jumlah"
                        required
                        value={form.data.quantity}
                        onChange={(e) => form.setData('quantity', e.target.value)}
                        error={receiveErrors.quantity}
                    />
                    <Input
                        id="receive-supplier"
                        label="Pemasok"
                        maxLength={150}
                        value={form.data.supplier_name}
                        onChange={(e) => form.setData('supplier_name', e.target.value)}
                        error={receiveErrors.supplier_name}
                    />
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Input
                            id="receive-reference"
                            label="No. faktur"
                            maxLength={100}
                            value={form.data.purchase_reference}
                            onChange={(e) => form.setData('purchase_reference', e.target.value)}
                            error={receiveErrors.purchase_reference}
                        />
                        <Input
                            id="receive-unit-cost"
                            type="number"
                            min={0}
                            step="any"
                            label="Harga satuan"
                            hint="Rupiah per satuan barang."
                            value={form.data.unit_cost}
                            onChange={(e) => form.setData('unit_cost', e.target.value)}
                            error={receiveErrors.unit_cost}
                        />
                    </div>
                    <Textarea
                        id="receive-note"
                        label="Catatan"
                        value={form.data.note}
                        onChange={(e) => form.setData('note', e.target.value)}
                        rows={3}
                        maxLength={500}
                        error={receiveErrors.note}
                    />
                    {formError && (
                        <p role="alert" className="text-sm text-destructive-text">
                            {formError}
                        </p>
                    )}
                    <div className="flex justify-end gap-3 pt-4">
                        <Button type="button" variant="outline" onClick={closeReceive}>
                            Batal
                        </Button>
                        <Button type="submit" disabled={form.processing} loading={form.processing}>
                            {form.processing ? 'Menyimpan...' : 'Simpan'}
                        </Button>
                    </div>
                </form>
            </Modal>

            <Modal
                open={canTransfer && transferOpen}
                onClose={closeTransfer}
                title="Pindahkan stok"
                size="lg"
            >
                <form onSubmit={submitTransfer} className="space-y-4">
                    <RemoteSearchSelect
                        id="transfer-from"
                        field="location"
                        label="Dari"
                        placeholder="Cari kode atau nama lokasi asal"
                        required
                        options={locationOptions}
                        value={transferForm.data.from_location_id}
                        onChange={(value) => transferForm.setData('from_location_id', value)}
                        error={transferForm.errors.from_location_id}
                    />
                    <RemoteSearchSelect
                        id="transfer-to"
                        field="location"
                        label="Ke"
                        placeholder="Cari kode atau nama lokasi tujuan"
                        required
                        options={locationOptions}
                        value={transferForm.data.to_location_id}
                        onChange={(value) => transferForm.setData('to_location_id', value)}
                        error={transferForm.errors.to_location_id}
                    />
                    <RemoteSearchSelect
                        id="transfer-product"
                        field="product"
                        label="Barang"
                        placeholder="Cari nama atau SKU"
                        required
                        options={productOptions}
                        value={transferForm.data.product_id}
                        onChange={(value) => transferForm.setData('product_id', value)}
                        error={transferForm.errors.product_id}
                    />
                    <StockFigure
                        lookup={transferSource}
                        label="Tersedia di lokasi asal"
                        pick="available"
                    />
                    <Input
                        id="transfer-quantity"
                        type="number"
                        min={0.01}
                        step="any"
                        label="Jumlah"
                        required
                        value={transferForm.data.quantity}
                        onChange={(e) => transferForm.setData('quantity', e.target.value)}
                        error={transferForm.errors.quantity}
                    />
                    <Textarea
                        id="transfer-note"
                        label="Catatan"
                        value={transferForm.data.note}
                        onChange={(e) => transferForm.setData('note', e.target.value)}
                        rows={3}
                        maxLength={500}
                        error={transferForm.errors.note}
                    />
                    {transferError && (
                        <p role="alert" className="text-sm text-destructive-text">
                            {transferError}
                        </p>
                    )}
                    <div className="flex justify-end gap-3 pt-4">
                        <Button type="button" variant="outline" onClick={closeTransfer}>
                            Batal
                        </Button>
                        <Button
                            type="submit"
                            disabled={transferForm.processing}
                            loading={transferForm.processing}
                        >
                            {transferForm.processing ? 'Memindahkan...' : 'Pindahkan'}
                        </Button>
                    </div>
                </form>
            </Modal>

            <Modal
                open={canAdjustRequest && adjustOpen}
                onClose={closeAdjust}
                title="Ajukan Penyesuaian Stok"
                size="lg"
            >
                <form onSubmit={submitAdjust} className="space-y-4">
                    <p className="text-sm text-muted-foreground">
                        Stok baru berubah setelah pengguna lain yang berwenang menyetujui.
                    </p>
                    <RemoteSearchSelect
                        id="adjust-product"
                        field="product"
                        label="Barang"
                        placeholder="Cari nama atau SKU"
                        required
                        options={productOptions}
                        value={adjustForm.data.product_id}
                        onChange={(value) => adjustForm.setData('product_id', value)}
                        error={adjustForm.errors.product_id}
                    />
                    <RemoteSearchSelect
                        id="adjust-location"
                        field="location"
                        label="Lokasi"
                        placeholder="Cari kode atau nama lokasi"
                        required
                        options={locationOptions}
                        value={adjustForm.data.location_id}
                        onChange={(value) => adjustForm.setData('location_id', value)}
                        error={adjustForm.errors.location_id}
                    />
                    <StockFigure lookup={adjustStock} label="Stok sistem" pick="quantity" />
                    <Input
                        id="adjust-quantity"
                        type="number"
                        min={0}
                        step="any"
                        label="Jumlah baru (hasil hitung fisik)"
                        required
                        value={adjustForm.data.new_quantity}
                        onChange={(e) => adjustForm.setData('new_quantity', e.target.value)}
                        error={adjustForm.errors.new_quantity}
                    />
                    <Textarea
                        id="adjust-note"
                        label="Alasan"
                        required
                        value={adjustForm.data.note}
                        onChange={(e) => adjustForm.setData('note', e.target.value)}
                        rows={3}
                        maxLength={500}
                        error={adjustForm.errors.note}
                    />
                    <div className="flex justify-end gap-3 pt-4">
                        <Button type="button" variant="outline" onClick={closeAdjust}>
                            Batal
                        </Button>
                        <Button
                            type="submit"
                            disabled={adjustForm.processing}
                            loading={adjustForm.processing}
                        >
                            {adjustForm.processing ? 'Mengirim...' : 'Ajukan'}
                        </Button>
                    </div>
                </form>
            </Modal>

            <Modal
                open={decision !== null}
                onClose={closeDecision}
                title={
                    decision?.mode === 'reject'
                        ? 'Tolak Penyesuaian Stok'
                        : 'Setujui Penyesuaian Stok'
                }
                size="lg"
            >
                {decision && (
                    <form onSubmit={submitDecision} className="space-y-4">
                        <p className="text-sm">
                            <span className="font-medium">{decision.row.product?.name ?? '-'}</span>{' '}
                            di {locationLabel(decision.row.location)}:{' '}
                            {formatNumber(decision.row.old_quantity)} →{' '}
                            {formatNumber(decision.row.new_quantity)}
                        </p>
                        <p className="text-sm text-muted-foreground">
                            Alasan pemohon: {decision.row.reason}
                        </p>
                        {decision.mode === 'reject' && (
                            <Textarea
                                id="decision-reason"
                                label="Alasan penolakan"
                                required
                                minLength={3}
                                maxLength={500}
                                rows={3}
                                value={decisionForm.data.rejection_reason}
                                onChange={(e) =>
                                    decisionForm.setData('rejection_reason', e.target.value)
                                }
                                error={decisionForm.errors.rejection_reason}
                            />
                        )}
                        {decisionError && (
                            <p role="alert" className="text-sm text-destructive-text">
                                {decisionError}
                            </p>
                        )}
                        <div className="flex justify-end gap-3 pt-4">
                            <Button type="button" variant="outline" onClick={closeDecision}>
                                Batal
                            </Button>
                            <Button
                                type="submit"
                                variant={decision.mode === 'reject' ? 'danger' : 'default'}
                                disabled={decisionForm.processing}
                                loading={decisionForm.processing}
                            >
                                {decision.mode === 'reject' ? 'Tolak' : 'Setujui'}
                            </Button>
                        </div>
                    </form>
                )}
            </Modal>
        </AdminLayout>
    );
}
