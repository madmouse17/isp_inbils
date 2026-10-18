import { useState } from 'react';
import { Link, router } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import { Button, Card, CardContent, Modal, Textarea } from '@/Components/ui';
import { DataTable, PageHeader, type DataTableColumn } from '@/Components/composite';
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

export default function Receivables({
    rows,
    totals,
    filters,
    can,
}: {
    rows: PaginationLike<Row>;
    totals: Omit<Row, 'customer_id' | 'customer' | 'subscriptions'>;
    filters: Filters;
    can: { suspend: boolean; block: boolean };
}) {
    const company = useCompany();
    const [suspending, setSuspending] = useState<SubRef | null>(null);
    const [blocking, setBlocking] = useState<SubRef | null>(null);
    const [reason, setReason] = useState('');
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
                <Modal
                    open={suspending !== null}
                    onClose={closeSuspend}
                    title={suspending ? `Isolir langganan ${suspending.code}?` : undefined}
                >
                    <div className="space-y-4">
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
