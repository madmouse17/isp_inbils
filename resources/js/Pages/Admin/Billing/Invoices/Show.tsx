import type { FormEvent } from 'react';
import { useState } from 'react';
import { Link, router, useForm } from '@inertiajs/react';
import { AlertTriangle, ArrowLeft, ChevronRight } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import {
    Alert,
    AlertDescription,
    AlertTitle,
    Button,
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
    DatePicker,
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
import {
    MoneyInput,
    PageHeader,
    RefundAttemptDialog,
    type RefundTarget,
} from '@/Components/composite';
import { ConfirmDialog } from '@/Components/composite/ConfirmDialog';
import { useCompany } from '@/hooks/useCompany';
import { todayInZone } from '@/lib/dateRange';
import {
    formatDate,
    formatDateTime,
    formatDecimal,
    formatPercent,
    formatRupiah,
} from '@/lib/format';
import {
    INVOICE_SOURCE,
    INVOICE_STATUS,
    INVOICE_TYPE,
    optionsOf,
    PAYMENT_ATTEMPT_STATUS,
    PAYMENT_METHOD,
    PAYMENT_STATE,
} from '@/lib/statuses';
import type { Invoice, InvoiceCan, InvoiceItemEntry, InvoicePaymentAttempt } from '@/types/billing';

/** What the invoice printed when it was issued; null on a draft and on invoices that predate the snapshot. */
interface InvoiceSnapshots {
    customer_name_snapshot?: string | null;
    customer_address_snapshot?: string | null;
    customer_tax_id_snapshot?: string | null;
    package_name_snapshot?: string | null;
}

/** What Midtrans called about one attempt: when it came, what it said and what Inbils answered. */
interface AttemptCallback {
    at: string | null;
    transaction_status: string | null;
    status_code: string | null;
    fraud_status: string | null;
    /** The status code Inbils answered with. */
    http: number | null;
}

/** The attempt as this page gets it: the resource adds the Indonesian labels and the last callbacks. */
interface OnlineAttempt extends InvoicePaymentAttempt {
    reason_label: string | null;
    method_label: string | null;
    callbacks: AttemptCallback[];
}

interface ShowProps extends Record<string, unknown> {
    invoice: { data: Invoice & InvoiceSnapshots };
    onlinePayments: { enabled: boolean; attempts: { data: OnlineAttempt[] } };
    /** The company's PPN in percent: the item dialog starts from it. */
    tax_rate: number;
    can: InvoiceCan & {
        /** Download the receipt of a paid invoice: `billing.pdf.view`. */
        receipt: boolean;
    };
}

// Staff record these; `midtrans` is written only by the payment gateway (RecordPaymentRequest).
const MANUAL_METHODS = optionsOf(PAYMENT_METHOD).filter((option) => option.value !== 'midtrans');

const PAYMENT_METHOD_KEY = 'inbils.payment.method';

const FUTURE_PAID_AT = 'Tanggal bayar tidak boleh setelah hari ini.';

/** The method the last payment was booked with, while it is still one staff can pick; cash otherwise. */
function rememberedPaymentMethod(): string {
    try {
        const stored = window.localStorage.getItem(PAYMENT_METHOD_KEY);

        return MANUAL_METHODS.find((option) => option.value === stored)?.value ?? 'cash';
    } catch {
        // Storage can be blocked (browser policy); the dialog must still open, just without a memory.
        return 'cash';
    }
}

function rememberPaymentMethod(method: string): void {
    try {
        window.localStorage.setItem(PAYMENT_METHOD_KEY, method);
    } catch {
        // Blocked storage: the next payment starts from cash.
    }
}

export default function Show({ invoice, onlinePayments, tax_rate: taxRate, can }: ShowProps) {
    const inv = invoice.data;
    const attempts = onlinePayments.attempts.data;
    // Newest first: recording a payment closes every pending page, so any of them is a warning.
    const openPage = attempts.find((attempt) => attempt.status === 'pending');
    const items = inv.items ?? [];
    const company = useCompany();
    const today = todayInZone(company?.timezone);
    const [paymentModal, setPaymentModal] = useState(false);
    const [itemModal, setItemModal] = useState(false);
    const [cancelModal, setCancelModal] = useState(false);
    const [reverseTarget, setReverseTarget] = useState<string | null>(null);
    const [refundTarget, setRefundTarget] = useState<RefundTarget | null>(null);
    const [removeTarget, setRemoveTarget] = useState<InvoiceItemEntry | null>(null);
    const [removing, setRemoving] = useState(false);
    const [sendOpen, setSendOpen] = useState(false);
    const [sending, setSending] = useState(false);
    const [cancelTarget, setCancelTarget] = useState<OnlineAttempt | null>(null);
    const [cancelling, setCancelling] = useState(false);
    const [cancelError, setCancelError] = useState<string>();
    const { data, setData, post, processing, reset, errors, clearErrors } = useForm({
        amount: '',
        method: 'cash',
        paid_at: '',
        reference: '',
        notes: '',
        description: '',
        quantity: '1',
        unit_price: '',
        discount_amount: '0',
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
    const canSend = isDraft && can.send;
    const canEditItems = isDraft && can.update;
    const canRecordPayment =
        can.record_payment &&
        ['sent', 'partial', 'overdue'].includes(inv.status) &&
        Number(inv.sisa) > 0;
    const canCancel = can.cancel && !['cancelled', 'paid'].includes(inv.status);
    // The server refuses a draft with nothing to bill; the button says why before it is pressed.
    const nothingToSend = isDraft && (items.length === 0 || Number(inv.total) <= 0);

    // The balance changes after every payment, so the form takes its values when it opens, not when the page loads.
    const openPayment = () => {
        clearErrors();
        setData({ ...data, amount: inv.sisa, method: rememberedPaymentMethod(), paid_at: today });
        setPaymentModal(true);
    };

    const submitPayment = (e: FormEvent) => {
        e.preventDefault();
        post(route('admin.invoices.payments.store', inv.id), {
            onSuccess: () => {
                rememberPaymentMethod(data.method);
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

    const closeCancelDialog = () => {
        setCancelTarget(null);
        setCancelError(undefined);
    };

    // The server refuses a page that is no longer pending (`cancel` error); the dialog keeps the refusal next to the choice.
    const cancelAttempt = () => {
        if (!cancelTarget) return;
        router.post(
            route('admin.payment-attempts.cancel', cancelTarget.id),
            {},
            {
                preserveScroll: true,
                onStart: () => {
                    setCancelling(true);
                    setCancelError(undefined);
                },
                onSuccess: closeCancelDialog,
                onError: (formErrors) => setCancelError(formErrors.cancel),
                onFinish: () => setCancelling(false),
            },
        );
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
                            {isDraft && can.update && (
                                <Button asChild variant="outline" size="sm">
                                    <Link href={route('admin.invoices.edit', inv.id)}>Ubah</Link>
                                </Button>
                            )}
                            {inv.status === 'paid' && can.receipt && (
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    onClick={() =>
                                        window.open(
                                            route('admin.invoices.receipt', inv.id),
                                            '_blank',
                                        )
                                    }
                                >
                                    Unduh kuitansi
                                </Button>
                            )}
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

                {(canSend || canEditItems || canRecordPayment || canCancel) && (
                    <Card>
                        <CardHeader>
                            <CardTitle>Aksi</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="flex flex-wrap items-start gap-2">
                                {canSend && (
                                    <div className="space-y-1">
                                        <Button
                                            type="button"
                                            variant="primary"
                                            disabled={nothingToSend}
                                            aria-describedby={
                                                nothingToSend ? 'send-blocked' : undefined
                                            }
                                            onClick={() => setSendOpen(true)}
                                        >
                                            Kirim tagihan
                                        </Button>
                                        {nothingToSend && (
                                            <p
                                                id="send-blocked"
                                                className="max-w-xs text-sm text-muted-foreground"
                                            >
                                                Tambahkan minimal satu item sebelum mengirim.
                                            </p>
                                        )}
                                    </div>
                                )}
                                {canRecordPayment && (
                                    <Button type="button" variant="primary" onClick={openPayment}>
                                        Catat pembayaran
                                    </Button>
                                )}
                                {canEditItems && (
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
                    description="Pelanggan menerima notifikasi di aplikasi Inbils, juga lewat WhatsApp bila aktif. Item tidak bisa diubah lagi."
                    details={[
                        `Pelanggan: ${customerName ?? '-'}`,
                        `Jumlah: ${formatRupiah(inv.total, company)}`,
                        `Jatuh tempo: ${formatDate(inv.due_date, company)}`,
                    ]}
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
                                    {canEditItems && (
                                        <TH>
                                            <span className="sr-only">Aksi</span>
                                        </TH>
                                    )}
                                </TR>
                            </THead>
                            <TBody>
                                {items.length === 0 ? (
                                    <TR>
                                        <TD
                                            colSpan={canEditItems ? 7 : 6}
                                            className="text-center text-muted-foreground"
                                        >
                                            Tidak ada item.
                                        </TD>
                                    </TR>
                                ) : (
                                    items.map((i) => (
                                        <TR key={i.id}>
                                            <TD>{i.description}</TD>
                                            <TD>{formatDecimal(i.quantity, 2)}</TD>
                                            <TD>{formatRupiah(i.unit_price, company)}</TD>
                                            <TD>{formatRupiah(i.discount_amount, company)}</TD>
                                            <TD>{formatPercent(i.tax_rate, 2)}</TD>
                                            <TD className="font-medium">
                                                {formatRupiah(i.line_total, company)}
                                            </TD>
                                            {canEditItems && (
                                                <TD>
                                                    <Button
                                                        type="button"
                                                        variant="destructive-outline"
                                                        size="sm"
                                                        onClick={() => setRemoveTarget(i)}
                                                    >
                                                        Hapus
                                                        <span className="sr-only">
                                                            {' '}
                                                            item {i.description}
                                                        </span>
                                                    </Button>
                                                </TD>
                                            )}
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
                                                {!p.cancelled_at && can.reverse_payment && (
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
                                Percobaan pembayaran lewat Midtrans untuk tagihan ini. Pembayaran
                                baru tercatat setelah Midtrans mengonfirmasinya.
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
                                                    {a.reason_label && (
                                                        <p
                                                            className={`mt-1 max-w-xs text-xs ${a.status === 'exception' ? 'text-destructive-text' : 'text-muted-foreground'}`}
                                                        >
                                                            Alasan: {a.reason_label}
                                                        </p>
                                                    )}
                                                    {a.refund && (
                                                        <div className="space-y-0.5 mt-1 max-w-xs text-xs break-words text-muted-foreground">
                                                            <p>
                                                                Dikembalikan{' '}
                                                                {formatDateTime(
                                                                    a.refund.at,
                                                                    company,
                                                                )}
                                                                {a.refund.by
                                                                    ? ` oleh ${a.refund.by}`
                                                                    : ''}
                                                            </p>
                                                            {a.refund.reference && (
                                                                <p>Ref {a.refund.reference}</p>
                                                            )}
                                                            {a.refund.note && (
                                                                <p>Catatan: {a.refund.note}</p>
                                                            )}
                                                        </div>
                                                    )}
                                                    {a.status === 'exception' && can.refund && (
                                                        <Button
                                                            type="button"
                                                            variant="outline"
                                                            size="sm"
                                                            className="mt-2"
                                                            onClick={() =>
                                                                setRefundTarget({
                                                                    id: a.id,
                                                                    invoiceNumber: inv.number,
                                                                    amount: a.amount,
                                                                })
                                                            }
                                                        >
                                                            Tandai dikembalikan
                                                        </Button>
                                                    )}
                                                    {onlinePayments.enabled &&
                                                        a.status === 'pending' &&
                                                        can.record_payment && (
                                                            <Button
                                                                type="button"
                                                                variant="destructive-outline"
                                                                size="sm"
                                                                className="mt-2"
                                                                onClick={() => setCancelTarget(a)}
                                                            >
                                                                Batalkan di Midtrans
                                                                <span className="sr-only">
                                                                    {' '}
                                                                    untuk pembayaran{' '}
                                                                    {formatRupiah(
                                                                        a.amount,
                                                                        company,
                                                                    )}
                                                                </span>
                                                            </Button>
                                                        )}
                                                    {a.callbacks.length === 0 ? (
                                                        <p className="mt-2 max-w-xs text-xs text-muted-foreground">
                                                            Tidak ada notifikasi tercatat.
                                                        </p>
                                                    ) : (
                                                        <details className="group mt-1 max-w-xs text-xs">
                                                            <summary className="inline-flex min-h-11 cursor-pointer items-center gap-1 rounded-sm font-medium text-foreground underline-offset-2 hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-hidden sm:min-h-8">
                                                                <ChevronRight
                                                                    className="size-4 transition-transform group-open:rotate-90 motion-reduce:transition-none"
                                                                    aria-hidden="true"
                                                                />
                                                                Riwayat notifikasi Midtrans
                                                            </summary>
                                                            <ul className="space-y-1 text-muted-foreground">
                                                                {a.callbacks.map(
                                                                    (callback, index) => (
                                                                        <li
                                                                            key={`${callback.at}-${index}`}
                                                                            className="tabular-nums"
                                                                        >
                                                                            Diterima{' '}
                                                                            {formatDateTime(
                                                                                callback.at,
                                                                                company,
                                                                            )}{' '}
                                                                            ·{' '}
                                                                            {callback.transaction_status ??
                                                                                'tanpa status'}{' '}
                                                                            · dijawab{' '}
                                                                            {callback.http ?? '-'}
                                                                        </li>
                                                                    ),
                                                                )}
                                                            </ul>
                                                            {a.callbacks.length >= 5 && (
                                                                <p className="mt-1 text-muted-foreground">
                                                                    Notifikasi yang lebih lama tidak
                                                                    ditampilkan.
                                                                </p>
                                                            )}
                                                        </details>
                                                    )}
                                                </TD>
                                                <TD className="font-medium">
                                                    {formatRupiah(a.amount, company)}
                                                </TD>
                                                <TD>{a.method_label ?? <EmptyCell />}</TD>
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
                        {openPage && (
                            <Alert variant="warning" role="status">
                                <AlertTriangle aria-hidden="true" className="size-4" />
                                <AlertTitle>Ada pembayaran online yang masih terbuka</AlertTitle>
                                <AlertDescription>
                                    <p>
                                        Halaman bayar{' '}
                                        <span className="font-mono break-all">
                                            {openPage.order_id}
                                        </span>{' '}
                                        dibuat {formatDateTime(openPage.created_at, company)}.
                                        Mencatat pembayaran ini menutup halaman itu. Bila pelanggan
                                        tetap membayar online, pembayarannya masuk daftar Perlu
                                        dicek di Tunggakan.
                                    </p>
                                </AlertDescription>
                            </Alert>
                        )}
                        <div className="space-y-2">
                            <MoneyInput
                                id="payment-amount"
                                label="Jumlah"
                                value={data.amount === '' ? null : Number(data.amount)}
                                onChange={(value) =>
                                    setData('amount', value === null ? '' : String(value))
                                }
                                hint={`Sisa tagihan: ${formatRupiah(inv.sisa, company)}`}
                                error={errors.amount}
                                required
                            />
                            {Number(data.amount) !== Number(inv.sisa) && (
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    onClick={() => setData('amount', inv.sisa)}
                                >
                                    Lunas penuh
                                </Button>
                            )}
                        </div>
                        <DatePicker
                            id="payment-paid-at"
                            label="Tanggal bayar"
                            value={data.paid_at}
                            onChange={(value) => setData('paid_at', value)}
                            hint="Ubah bila uang diterima sebelum hari ini."
                            error={
                                errors.paid_at ??
                                (data.paid_at > today ? FUTURE_PAID_AT : undefined)
                            }
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
                        </div>
                        <p className="text-sm text-muted-foreground">
                            Pajak PPN {formatDecimal(taxRate, 2)}% mengikuti pengaturan perusahaan,
                            dihitung per baris.
                        </p>
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

                <RefundAttemptDialog target={refundTarget} onClose={() => setRefundTarget(null)} />

                <ConfirmDialog
                    open={cancelTarget !== null}
                    title="Batalkan pembayaran online?"
                    description="Halaman bayar ini ditutup dan Midtrans diminta membatalkan transaksinya. Pelanggan tidak bisa lagi membayar lewat halaman itu, tetapi bisa membuka halaman bayar baru dari aplikasi."
                    details={
                        cancelTarget
                            ? [
                                  `Jumlah: ${formatRupiah(cancelTarget.amount, company)}`,
                                  `Dibuat: ${formatDateTime(cancelTarget.created_at, company)}`,
                                  ...(cancelTarget.method_label
                                      ? [`Metode: ${cancelTarget.method_label}`]
                                      : []),
                              ]
                            : undefined
                    }
                    confirmLabel="Batalkan di Midtrans"
                    tone="danger"
                    processing={cancelling}
                    error={cancelError}
                    onConfirm={cancelAttempt}
                    onClose={closeCancelDialog}
                />

                <ConfirmDialog
                    open={removeTarget !== null}
                    title={`Hapus item ${removeTarget?.description ?? ''}?`}
                    description="Total tagihan dihitung ulang tanpa item ini."
                    confirmLabel="Hapus item"
                    tone="danger"
                    processing={removing}
                    onConfirm={() => {
                        if (!removeTarget) return;
                        router.delete(
                            route('admin.invoices.items.destroy', [inv.id, removeTarget.id]),
                            {
                                preserveScroll: true,
                                onStart: () => setRemoving(true),
                                onSuccess: () => setRemoveTarget(null),
                                onFinish: () => setRemoving(false),
                            },
                        );
                    }}
                    onClose={() => setRemoveTarget(null)}
                />
            </div>
        </AdminLayout>
    );
}
