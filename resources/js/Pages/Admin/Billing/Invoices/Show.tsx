import type { FormEvent } from 'react';
import { useState } from 'react';
import { Link, router, useForm } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import {
    Button,
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
    EmptyCell,
    EnumBadge,
    Input,
    Modal,
    NativeSelect,
    Table,
    TBody,
    TD,
    TH,
    THead,
    TR,
} from '@/Components/ui';
import { PageHeader } from '@/Components/composite';
import { ConfirmDialog } from '@/Components/composite/ConfirmDialog';
import { useCompany } from '@/hooks/useCompany';
import { usePermission } from '@/hooks/usePermission';
import { formatDate, formatDateTime, formatRupiah } from '@/lib/format';
import {
    INVOICE_SOURCE,
    INVOICE_STATUS,
    INVOICE_TYPE,
    optionsOf,
    PAYMENT_ATTEMPT_STATUS,
    PAYMENT_METHOD,
    PAYMENT_STATE,
} from '@/lib/statuses';
import type { Invoice } from '@/types/billing';
import type { PaymentAttemptEntry } from '@/types/portal';

/** What the invoice printed when it was issued; null on a draft and on invoices that predate the snapshot. */
interface InvoiceSnapshots {
    customer_name_snapshot?: string | null;
    customer_address_snapshot?: string | null;
    customer_tax_id_snapshot?: string | null;
    package_name_snapshot?: string | null;
}

interface ShowProps extends Record<string, unknown> {
    invoice: { data: Invoice & InvoiceSnapshots };
    onlinePayments: { enabled: boolean; attempts: { data: PaymentAttemptEntry[] } };
}

// Staff record these; `midtrans` is written only by the payment gateway (RecordPaymentRequest).
const MANUAL_METHODS = optionsOf(PAYMENT_METHOD).filter((option) => option.value !== 'midtrans');

export default function Show({ invoice, onlinePayments }: ShowProps) {
    const inv = invoice.data;
    const attempts = onlinePayments.attempts.data;
    const company = useCompany();
    const { can } = usePermission();
    const [paymentModal, setPaymentModal] = useState(false);
    const [itemModal, setItemModal] = useState(false);
    const [cancelModal, setCancelModal] = useState(false);
    const [reverseTarget, setReverseTarget] = useState<string | null>(null);
    const [sendOpen, setSendOpen] = useState(false);
    const [sending, setSending] = useState(false);
    const { data, setData, post, processing, reset, errors } = useForm({
        amount: '',
        method: 'cash',
        reference: '',
        notes: '',
        description: '',
        quantity: '1',
        unit_price: '',
        discount_amount: '0',
        tax_rate: '0',
        reason: '',
    });

    const isDraft = inv.status === 'draft';
    // An issued invoice keeps the name printed on it; a draft follows the live customer.
    const customerName = isDraft
        ? inv.customer?.name
        : (inv.customer_name_snapshot ?? inv.customer?.name);
    const customerNameChanged =
        !isDraft &&
        Boolean(inv.customer_name_snapshot) &&
        inv.customer_name_snapshot !== inv.customer?.name;
    const canRecordPayment =
        ['sent', 'partial', 'overdue'].includes(inv.status) && Number(inv.sisa) > 0;
    const canCancel = !['cancelled', 'paid'].includes(inv.status);

    const submitPayment = (e: FormEvent) => {
        e.preventDefault();
        post(route('admin.invoices.payments.store', inv.id), {
            onSuccess: () => {
                setPaymentModal(false);
                reset();
            },
        });
    };

    const submitItem = (e: FormEvent) => {
        e.preventDefault();
        post(route('admin.invoices.items.store', inv.id), {
            onSuccess: () => {
                setItemModal(false);
                reset();
            },
        });
    };

    const submitCancel = (e: FormEvent) => {
        e.preventDefault();
        post(route('admin.invoices.cancel', inv.id), {
            onSuccess: () => {
                setCancelModal(false);
                reset();
            },
        });
    };

    const submitReverse = (e: FormEvent) => {
        e.preventDefault();
        if (reverseTarget === null) return;
        post(route('admin.invoices.payments.reverse', [inv.id, reverseTarget]), {
            onSuccess: () => {
                setReverseTarget(null);
                reset();
            },
        });
    };

    return (
        <AdminLayout title={`Tagihan ${inv.number}`}>
            <div className="space-y-6">
                <PageHeader
                    title={`Tagihan ${inv.number}`}
                    subtitle="Rincian tagihan, item, pembayaran, dan percobaan pembayaran online."
                    actions={
                        <>
                            <Button
                                asChild
                                variant="outline"
                                size="sm"
                                leftIcon={<ArrowLeft className="size-4" />}
                            >
                                <Link href={route('admin.invoices.index')}>Kembali</Link>
                            </Button>
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                onClick={() =>
                                    window.open(route('admin.invoices.pdf', inv.id), '_blank')
                                }
                            >
                                Unduh PDF
                            </Button>
                        </>
                    }
                />

                <div className="grid gap-6 xl:grid-cols-[2fr_1fr]">
                    <Card>
                        <CardHeader>
                            <CardTitle>Detail</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-3 text-sm">
                            <p>
                                <span className="text-muted-foreground">Pelanggan: </span>
                                {customerName ?? '-'}
                                {customerNameChanged && (
                                    <span className="text-xs text-muted-foreground">
                                        {' '}
                                        (saat terbit)
                                    </span>
                                )}
                            </p>
                            {!isDraft && inv.customer_address_snapshot && (
                                <p>
                                    <span className="text-muted-foreground">Alamat: </span>
                                    {inv.customer_address_snapshot}
                                </p>
                            )}
                            {!isDraft && inv.customer_tax_id_snapshot && (
                                <p>
                                    <span className="text-muted-foreground">NPWP: </span>
                                    {inv.customer_tax_id_snapshot}
                                </p>
                            )}
                            {!isDraft && inv.package_name_snapshot && (
                                <p>
                                    <span className="text-muted-foreground">Paket: </span>
                                    {inv.package_name_snapshot}
                                </p>
                            )}
                            <p>
                                <span className="text-muted-foreground">Langganan: </span>
                                {inv.subscription?.code ?? '-'}
                            </p>
                            <p>
                                <span className="text-muted-foreground">Tanggal terbit: </span>
                                {formatDate(inv.issue_date, company)}
                            </p>
                            <p>
                                <span className="text-muted-foreground">Jatuh tempo: </span>
                                {formatDate(inv.due_date, company)}
                            </p>
                            <p>
                                <span className="text-muted-foreground">Jenis: </span>
                                <EnumBadge map={INVOICE_TYPE} value={inv.type} />
                            </p>
                            <p>
                                <span className="text-muted-foreground">Sumber: </span>
                                <EnumBadge map={INVOICE_SOURCE} value={inv.source} />
                            </p>
                            {inv.notes && (
                                <p>
                                    <span className="text-muted-foreground">Catatan: </span>
                                    {inv.notes}
                                </p>
                            )}
                            {inv.cancel_reason && (
                                <p className="text-destructive-text">
                                    <span className="text-muted-foreground">
                                        Alasan pembatalan:{' '}
                                    </span>
                                    {inv.cancel_reason}
                                </p>
                            )}
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader>
                            <CardTitle>Ringkasan</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-3 text-sm">
                            <p>
                                <span className="text-muted-foreground">Status: </span>
                                <EnumBadge map={INVOICE_STATUS} value={inv.status} />
                            </p>
                            <p>
                                <span className="text-muted-foreground">Subtotal: </span>
                                {formatRupiah(inv.subtotal, company)}
                            </p>
                            <p>
                                <span className="text-muted-foreground">Pajak: </span>
                                {formatRupiah(inv.tax_amount, company)}
                            </p>
                            <p>
                                <span className="text-muted-foreground">Diskon: </span>
                                {formatRupiah(inv.discount_amount, company)}
                            </p>
                            <p className="text-lg font-bold">
                                <span className="text-muted-foreground">Total: </span>
                                {formatRupiah(inv.total, company)}
                            </p>
                            <p>
                                <span className="text-muted-foreground">Terbayar: </span>
                                {formatRupiah(inv.paid_amount, company)}
                            </p>
                            <p className="font-medium">
                                <span className="text-muted-foreground">Sisa: </span>
                                {formatRupiah(inv.sisa, company)}
                            </p>
                        </CardContent>
                    </Card>
                </div>

                {(isDraft || canRecordPayment || canCancel) && (
                    <Card>
                        <CardHeader>
                            <CardTitle>Aksi</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="flex flex-wrap gap-2">
                                {isDraft && (
                                    <Button
                                        type="button"
                                        variant="primary"
                                        onClick={() => setSendOpen(true)}
                                    >
                                        Kirim tagihan
                                    </Button>
                                )}
                                {canRecordPayment && (
                                    <Button
                                        type="button"
                                        variant="primary"
                                        onClick={() => setPaymentModal(true)}
                                    >
                                        Catat pembayaran
                                    </Button>
                                )}
                                {isDraft && (
                                    <Button
                                        type="button"
                                        variant="outline"
                                        onClick={() => setItemModal(true)}
                                    >
                                        Tambah item
                                    </Button>
                                )}
                                {canCancel && (
                                    <Button
                                        type="button"
                                        variant="destructive-outline"
                                        onClick={() => setCancelModal(true)}
                                    >
                                        Batalkan tagihan
                                    </Button>
                                )}
                            </div>
                        </CardContent>
                    </Card>
                )}

                <ConfirmDialog
                    open={sendOpen}
                    title={`Kirim tagihan ${inv.number}?`}
                    description="Tagihan diterbitkan ke pelanggan. Item tidak bisa ditambah lagi."
                    confirmLabel="Kirim tagihan"
                    processing={sending}
                    onConfirm={() =>
                        router.post(
                            route('admin.invoices.send', inv.id),
                            {},
                            {
                                onStart: () => setSending(true),
                                onSuccess: () => setSendOpen(false),
                                onFinish: () => setSending(false),
                            },
                        )
                    }
                    onClose={() => setSendOpen(false)}
                />

                <Card>
                    <CardHeader>
                        <CardTitle>Item</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <Table>
                            <THead>
                                <TR>
                                    <TH>Deskripsi</TH>
                                    <TH>Jml</TH>
                                    <TH>Harga satuan</TH>
                                    <TH>Diskon</TH>
                                    <TH>Pajak</TH>
                                    <TH>Total baris</TH>
                                </TR>
                            </THead>
                            <TBody>
                                {(inv.items ?? []).length === 0 ? (
                                    <TR>
                                        <TD
                                            colSpan={6}
                                            className="text-center text-muted-foreground"
                                        >
                                            Tidak ada item.
                                        </TD>
                                    </TR>
                                ) : (
                                    (inv.items ?? []).map((i) => (
                                        <TR key={i.id}>
                                            <TD>{i.description}</TD>
                                            <TD>{Number(i.quantity)}</TD>
                                            <TD>{formatRupiah(i.unit_price, company)}</TD>
                                            <TD>{formatRupiah(i.discount_amount, company)}</TD>
                                            <TD>{Number(i.tax_rate)}%</TD>
                                            <TD className="font-medium">
                                                {formatRupiah(i.line_total, company)}
                                            </TD>
                                        </TR>
                                    ))
                                )}
                            </TBody>
                        </Table>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Pembayaran</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <Table>
                            <THead>
                                <TR>
                                    <TH>Jumlah</TH>
                                    <TH>Metode</TH>
                                    <TH>Referensi</TH>
                                    <TH>Dibayar pada</TH>
                                    <TH>Status</TH>
                                    <TH>
                                        <span className="sr-only">Aksi</span>
                                    </TH>
                                </TR>
                            </THead>
                            <TBody>
                                {(inv.payments ?? []).length === 0 ? (
                                    <TR>
                                        <TD
                                            colSpan={6}
                                            className="text-center text-muted-foreground"
                                        >
                                            Tidak ada pembayaran.
                                        </TD>
                                    </TR>
                                ) : (
                                    (inv.payments ?? []).map((p) => (
                                        <TR key={p.id}>
                                            <TD className="font-medium">
                                                {formatRupiah(p.amount, company)}
                                            </TD>
                                            <TD>
                                                <EnumBadge map={PAYMENT_METHOD} value={p.method} />
                                            </TD>
                                            <TD>{p.reference || <EmptyCell />}</TD>
                                            <TD className="text-sm">
                                                {formatDateTime(p.paid_at, company)}
                                            </TD>
                                            <TD>
                                                <EnumBadge
                                                    map={PAYMENT_STATE}
                                                    value={p.cancelled_at ? 'cancelled' : 'active'}
                                                />
                                            </TD>
                                            <TD>
                                                {!p.cancelled_at &&
                                                    can('billing.payment.reverse') && (
                                                        <Button
                                                            type="button"
                                                            variant="destructive-outline"
                                                            size="sm"
                                                            onClick={() => setReverseTarget(p.id)}
                                                        >
                                                            Batalkan pembayaran
                                                        </Button>
                                                    )}
                                            </TD>
                                        </TR>
                                    ))
                                )}
                            </TBody>
                        </Table>
                    </CardContent>
                </Card>

                {(onlinePayments.enabled || attempts.length > 0) && (
                    <Card>
                        <CardHeader>
                            <CardTitle>Pembayaran online</CardTitle>
                            <CardDescription>
                                Percobaan pembayaran lewat Midtrans untuk tagihan ini. Hanya bisa
                                dilihat: pembayaran baru tercatat setelah Midtrans mengonfirmasinya.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <Table>
                                <THead>
                                    <TR>
                                        <TH>Status</TH>
                                        <TH>Jumlah</TH>
                                        <TH>Metode</TH>
                                        <TH>ID transaksi</TH>
                                        <TH>Dibuat</TH>
                                        <TH>Selesai</TH>
                                    </TR>
                                </THead>
                                <TBody>
                                    {attempts.length === 0 ? (
                                        <TR>
                                            <TD
                                                colSpan={6}
                                                className="text-center text-muted-foreground"
                                            >
                                                Belum ada percobaan pembayaran online.
                                            </TD>
                                        </TR>
                                    ) : (
                                        attempts.map((a) => (
                                            <TR key={a.id}>
                                                <TD>
                                                    <EnumBadge
                                                        map={PAYMENT_ATTEMPT_STATUS}
                                                        value={a.status}
                                                    />
                                                    {a.status_reason && (
                                                        <p
                                                            className={`mt-1 max-w-xs text-xs ${a.status === 'exception' ? 'text-destructive-text' : 'text-muted-foreground'}`}
                                                        >
                                                            Alasan: {a.status_reason}
                                                        </p>
                                                    )}
                                                </TD>
                                                <TD className="font-medium">
                                                    {formatRupiah(a.amount, company)}
                                                </TD>
                                                <TD>{a.payment_type ?? <EmptyCell />}</TD>
                                                <TD className="font-mono text-xs break-all">
                                                    {a.transaction_id ?? <EmptyCell />}
                                                </TD>
                                                <TD className="text-sm">
                                                    {formatDateTime(a.created_at, company)}
                                                </TD>
                                                <TD className="text-sm">
                                                    {formatDateTime(a.settled_at, company)}
                                                </TD>
                                            </TR>
                                        ))
                                    )}
                                </TBody>
                            </Table>
                        </CardContent>
                    </Card>
                )}

                <Modal
                    open={paymentModal}
                    onClose={() => setPaymentModal(false)}
                    title="Catat pembayaran"
                >
                    <form onSubmit={submitPayment} className="space-y-4">
                        <div className="text-sm text-muted-foreground">
                            Sisa tagihan: {formatRupiah(inv.sisa, company)}
                        </div>
                        <Input
                            label="Jumlah"
                            type="number"
                            step="0.01"
                            value={data.amount}
                            onChange={(e) => setData('amount', e.target.value)}
                            error={errors.amount}
                            required
                        />
                        <NativeSelect
                            label="Metode"
                            value={data.method}
                            onChange={(e) => setData('method', e.target.value)}
                            options={MANUAL_METHODS}
                            error={errors.method}
                        />
                        <Input
                            label="Referensi"
                            value={data.reference}
                            onChange={(e) => setData('reference', e.target.value)}
                            error={errors.reference}
                        />
                        <Input
                            label="Catatan"
                            value={data.notes}
                            onChange={(e) => setData('notes', e.target.value)}
                            error={errors.notes}
                        />
                        <div className="flex justify-end gap-2">
                            <Button
                                type="button"
                                variant="secondary"
                                onClick={() => setPaymentModal(false)}
                            >
                                Batal
                            </Button>
                            <Button type="submit" variant="primary" loading={processing}>
                                Simpan
                            </Button>
                        </div>
                    </form>
                </Modal>

                <Modal open={itemModal} onClose={() => setItemModal(false)} title="Tambah item">
                    <form onSubmit={submitItem} className="space-y-4">
                        <Input
                            label="Deskripsi"
                            value={data.description}
                            onChange={(e) => setData('description', e.target.value)}
                            error={errors.description}
                            required
                        />
                        <div className="grid gap-4 md:grid-cols-2">
                            <Input
                                label="Kuantitas"
                                type="number"
                                step="0.01"
                                value={data.quantity}
                                onChange={(e) => setData('quantity', e.target.value)}
                                error={errors.quantity}
                                required
                            />
                            <Input
                                label="Harga satuan"
                                type="number"
                                step="0.01"
                                value={data.unit_price}
                                onChange={(e) => setData('unit_price', e.target.value)}
                                error={errors.unit_price}
                                required
                            />
                            <Input
                                label="Diskon"
                                type="number"
                                step="0.01"
                                value={data.discount_amount}
                                onChange={(e) => setData('discount_amount', e.target.value)}
                                error={errors.discount_amount}
                            />
                            <Input
                                label="Pajak (%)"
                                type="number"
                                step="0.01"
                                value={data.tax_rate}
                                onChange={(e) => setData('tax_rate', e.target.value)}
                                error={errors.tax_rate}
                            />
                        </div>
                        <div className="flex justify-end gap-2">
                            <Button
                                type="button"
                                variant="secondary"
                                onClick={() => setItemModal(false)}
                            >
                                Batal
                            </Button>
                            <Button type="submit" variant="primary" loading={processing}>
                                Simpan
                            </Button>
                        </div>
                    </form>
                </Modal>

                <Modal
                    open={cancelModal}
                    onClose={() => setCancelModal(false)}
                    title="Batalkan tagihan"
                >
                    <form onSubmit={submitCancel} className="space-y-4">
                        <Input
                            label="Alasan"
                            value={data.reason}
                            onChange={(e) => setData('reason', e.target.value)}
                            error={errors.reason}
                            required
                        />
                        <div className="flex justify-end gap-2">
                            <Button
                                type="button"
                                variant="secondary"
                                onClick={() => setCancelModal(false)}
                            >
                                Kembali
                            </Button>
                            <Button type="submit" variant="danger" loading={processing}>
                                Konfirmasi pembatalan
                            </Button>
                        </div>
                    </form>
                </Modal>

                <Modal
                    open={reverseTarget !== null}
                    onClose={() => setReverseTarget(null)}
                    title="Batalkan pembayaran"
                >
                    <form onSubmit={submitReverse} className="space-y-4">
                        <div className="text-sm text-muted-foreground">
                            Membatalkan pembayaran mengurangi jumlah terbayar pada tagihan dan
                            menghitung ulang statusnya. Pembayaran ditandai dibatalkan beserta
                            pelakunya.
                        </div>
                        <Input
                            label="Alasan"
                            value={data.reason}
                            onChange={(e) => setData('reason', e.target.value)}
                            error={errors.reason}
                            required
                        />
                        <div className="flex justify-end gap-2">
                            <Button
                                type="button"
                                variant="secondary"
                                onClick={() => setReverseTarget(null)}
                            >
                                Kembali
                            </Button>
                            <Button type="submit" variant="danger" loading={processing}>
                                Konfirmasi pembatalan
                            </Button>
                        </div>
                    </form>
                </Modal>
            </div>
        </AdminLayout>
    );
}
