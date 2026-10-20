import { useState } from 'react';
import { Link, router } from '@inertiajs/react';
import { AlertTriangle } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import {
    Alert,
    AlertDescription,
    AlertTitle,
    Button,
    Card,
    CardContent,
    Modal,
    Textarea,
} from '@/Components/ui';
import {
    DataTable,
    PageHeader,
    RefundAttemptDialog,
    type DataTableColumn,
    type RefundTarget,
} from '@/Components/composite';
import { BlockedBadge, SubscriptionBlockModal } from '@/Components/composite/NetworkAccessBadges';
import { useCompany } from '@/hooks/useCompany';
import { useServerTable } from '@/hooks/useServerTable';
import { formatRupiah } from '@/lib/format';
import { toPagination, type PaginationLike } from '@/lib/pagination';

interface SubRef {
    id: string;
    code: string;
    status: string;
    blocked_at: string | null;
}

interface Row {
    customer_id: string;
    customer: string;
    current: number;
    b1_30: number;
    b31_60: number;
    b61_90: number;
    b90_plus: number;
    total: number;
    invoice_count: number;
    subscriptions: SubRef[];
}

interface Filters {
    sort_by: string;
    sort_dir: 'asc' | 'desc';
    per_page: number;
}

interface PaymentException {
    /** The attempt's public id; the refund route takes it. */
    id: string;
    invoice_id: string;
    invoice_number: string;
    order_id: string;
    amount: string;
    reason: string | null;
    updated_at: string;
}

export default function Receivables({
    rows,
    totals,
    filters,
    paymentExceptions,
    autoReactivate,
    can,
}: {
    rows: PaginationLike<Row>;
    totals: Omit<Row, 'customer_id' | 'customer' | 'subscriptions'>;
    filters: Filters;
    can: { suspend: boolean; block: boolean; refund: boolean };
    paymentExceptions: { count: number; items: PaymentException[] };
    /** Whether a paid-up customer is switched back on by itself; the isolir dialog tells which way. */
    autoReactivate: boolean;
}) {
    const company = useCompany();
    const [suspending, setSuspending] = useState<SubRef | null>(null);
    const [blocking, setBlocking] = useState<SubRef | null>(null);
    const [reason, setReason] = useState('');
    const [refundTarget, setRefundTarget] = useState<RefundTarget | null>(null);
    const pagination = toPagination(rows);
    const table = useServerTable({
        url: route('admin.billing.receivables'),
        filters: {
            sort: filters.sort_by,
            direction: filters.sort_dir,
            per_page: pagination.per_page,
        },
        only: ['rows', 'totals', 'filters'],
    });

    // A zero bucket is a dash, so the aging columns show only where money is owed.
    const rupiah = (n: number) => (n > 0 ? formatRupiah(n, company) : '-');

    const closeSuspend = () => {
        setSuspending(null);
        setReason('');
    };

    const onSort = (field: string, direction?: 'asc' | 'desc') => {
        table.onSort(field, direction);
    };

    const onPageChange = (page: number) => table.visit({ page });
    const onPerPageChange = (perPage: number) => table.visit({ per_page: perPage, page: 1 });

    const suspend = () => {
        if (!suspending) return;
        router.post(
            route('admin.subscriptions.suspend', suspending.id),
            { reason, source: 'overdue' },
            {
                onSuccess: () => {
                    setSuspending(null);
                    setReason('');
                },
            },
        );
    };

    const columns: DataTableColumn<Row>[] = [
        {
            key: 'customer',
            header: 'Pelanggan',
            sortable: true,
            cell: (row) => (
                <>
                    <Link
                        className="text-primary-text hover:underline"
                        href={route('admin.invoices.index', { customer_id: row.customer_id })}
                    >
                        {row.customer}
                    </Link>
                    <span className="ml-1 text-muted-foreground">
                        ({row.invoice_count} tagihan)
                    </span>
                </>
            ),
        },
        {
            key: 'current',
            header: 'Belum jatuh tempo',
            sortable: true,
            className: 'text-right',
            cell: (row) => rupiah(row.current),
        },
        {
            key: 'b1_30',
            header: '1-30 hari',
            sortable: true,
            className: 'text-right',
            cell: (row) => rupiah(row.b1_30),
        },
        {
            key: 'b31_60',
            header: '31-60 hari',
            sortable: true,
            className: 'text-right',
            cell: (row) => rupiah(row.b31_60),
        },
        {
            key: 'b61_90',
            header: '61-90 hari',
            sortable: true,
            className: 'text-right',
            cell: (row) => rupiah(row.b61_90),
        },
        {
            key: 'b90_plus',
            header: '>90 hari',
            sortable: true,
            className: 'text-right font-semibold text-destructive-text',
            cell: (row) => rupiah(row.b90_plus),
        },
        {
            key: 'total',
            header: 'Total',
            sortable: true,
            className: 'text-right font-semibold',
            cell: (row) => rupiah(row.total),
        },
        {
            key: 'actions',
            header: 'Aksi',
            cell: (row) => (
                <div className="flex flex-wrap justify-end gap-2">
                    {row.subscriptions.map((subscription) => (
                        <div key={subscription.id} className="flex flex-wrap items-center gap-2">
                            {subscription.blocked_at && <BlockedBadge />}
                            {can.suspend && subscription.status === 'active' && (
                                <Button
                                    type="button"
                                    variant="secondary"
                                    size="sm"
                                    onClick={() => {
                                        setReason('');
                                        setSuspending(subscription);
                                    }}
                                >
                                    Isolir {subscription.code}
                                </Button>
                            )}
                            {can.block && (
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    onClick={() => setBlocking(subscription)}
                                >
                                    {subscription.blocked_at ? 'Buka blokir' : 'Blokir'}{' '}
                                    {subscription.code}
                                </Button>
                            )}
                        </div>
                    ))}
                </div>
            ),
        },
    ];

    return (
        <AdminLayout title="Tunggakan">
            <div className="space-y-6">
                <PageHeader
                    title="Tunggakan"
                    subtitle="Pantau umur piutang pelanggan dan tindakan isolir."
                />
                {paymentExceptions.count > 0 && (
                    <Alert variant="danger">
                        <AlertTriangle aria-hidden="true" className="size-4" />
                        <AlertTitle>
                            {paymentExceptions.count} pembayaran online perlu dicek
                        </AlertTitle>
                        <AlertDescription>
                            <ul className="space-y-3 mt-2">
                                {paymentExceptions.items.map((item) => (
                                    <li
                                        key={item.id}
                                        className="flex flex-wrap items-start justify-between gap-x-4 gap-y-2"
                                    >
                                        <div className="space-y-0.5 min-w-0">
                                            <p>
                                                <Link
                                                    href={route(
                                                        'admin.invoices.show',
                                                        item.invoice_id,
                                                    )}
                                                    className="font-medium underline"
                                                >
                                                    {item.invoice_number}
                                                </Link>{' '}
                                                <span className="tabular-nums">
                                                    {formatRupiah(item.amount, company)}
                                                </span>
                                            </p>
                                            <p className="break-all">Order {item.order_id}</p>
                                            {item.reason && <p>{item.reason}</p>}
                                        </div>
                                        {can.refund && (
                                            <Button
                                                type="button"
                                                variant="outline"
                                                size="sm"
                                                onClick={() =>
                                                    setRefundTarget({
                                                        id: item.id,
                                                        invoiceNumber: item.invoice_number,
                                                        amount: item.amount,
                                                    })
                                                }
                                            >
                                                Tandai dikembalikan
                                                <span className="sr-only">
                                                    {' '}
                                                    untuk tagihan {item.invoice_number}
                                                </span>
                                            </Button>
                                        )}
                                    </li>
                                ))}
                            </ul>
                            <p className="mt-3">
                                Catat pembayarannya dengan referensi order ID tersebut, atau refund
                                di Midtrans.
                                {can.refund
                                    ? ' Setelah uangnya kembali ke pelanggan, tandai dikembalikan. Pembayaran itu lalu keluar dari daftar ini.'
                                    : ''}
                            </p>
                        </AlertDescription>
                    </Alert>
                )}
                <section
                    aria-label="Total piutang perusahaan"
                    className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4"
                >
                    {[
                        ['Belum jatuh tempo', totals.current],
                        ['1-30 hari', totals.b1_30],
                        ['31-60 hari', totals.b31_60],
                        ['61-90 hari', totals.b61_90],
                        ['>90 hari', totals.b90_plus],
                        ['Total piutang', totals.total],
                    ].map(([label, value]) => (
                        <Card key={label}>
                            <CardContent className="pt-4">
                                <p className="text-sm text-muted-foreground">{label}</p>
                                <p className="mt-1 font-semibold">
                                    {formatRupiah(Number(value), company)}
                                </p>
                            </CardContent>
                        </Card>
                    ))}
                </section>
                <Card>
                    <CardContent className="pt-6">
                        <DataTable
                            columns={columns}
                            data={pagination.data ?? []}
                            pagination={pagination}
                            rowKey="customer_id"
                            sortBy={filters.sort_by}
                            sortDir={filters.sort_dir}
                            onSort={onSort}
                            onPageChange={onPageChange}
                            onPerPageChange={onPerPageChange}
                            loading={table.processing}
                            itemLabel="pelanggan"
                            emptyTitle="Tidak ada tunggakan"
                            emptyDescription="Tagihan yang belum lunas di perusahaan ini akan muncul di sini."
                        />
                    </CardContent>
                </Card>
                <SubscriptionBlockModal target={blocking} onClose={() => setBlocking(null)} />
                <RefundAttemptDialog target={refundTarget} onClose={() => setRefundTarget(null)} />
                <Modal
                    open={suspending !== null}
                    onClose={closeSuspend}
                    title={suspending ? `Isolir langganan ${suspending.code}?` : undefined}
                >
                    <div className="space-y-4">
                        <p className="text-sm text-muted-foreground">
                            Pelanggan melihat status Diisolir dengan tagihan dan tombol Bayar.{' '}
                            {autoReactivate
                                ? 'Layanan aktif kembali otomatis setelah semua tagihan yang jatuh tempo dilunasi.'
                                : 'Layanan aktif kembali setelah staf mengaktifkannya kembali.'}
                        </p>
                        <label htmlFor="suspend-reason" className="block text-sm font-medium">
                            Alasan isolir (wajib)
                        </label>
                        <Textarea
                            id="suspend-reason"
                            placeholder="Tuliskan alasan isolir"
                            value={reason}
                            onChange={(e) => setReason(e.target.value)}
                        />
                        <div className="flex justify-end gap-2">
                            <Button type="button" variant="secondary" onClick={closeSuspend}>
                                Batal
                            </Button>
                            <Button type="button" onClick={suspend} disabled={!reason.trim()}>
                                Isolir
                            </Button>
                        </div>
                    </div>
                </Modal>
            </div>
        </AdminLayout>
    );
}
