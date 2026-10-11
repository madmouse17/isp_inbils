import { useEffect, useRef } from 'react';
import { Link, useForm, usePage } from '@inertiajs/react';
import { ArrowLeftIcon } from '@heroicons/react/24/outline';
import { Alert, Button, Card, CardContent, CardHeader, CardTitle } from '@/Components/ui';
import { PageHeader } from '@/Components/composite';
import { PortalInvoiceStatus } from '@/Components/composite/billing/PortalInvoiceStatus';
import PortalLayout from '@/Layouts/PortalLayout';
import { formatDate, formatDateTime, formatMoney, formatNumber } from '@/lib/format';
import type { PageProps } from '@/types';
import type { PortalInvoice, PortalPaymentState } from '@/types/portal';

interface ShowProps extends Record<string, unknown> {
    invoice: { data: PortalInvoice };
    payment: PortalPaymentState;
}

const methodLabels: Record<string, string> = {
    cash: 'Tunai',
    transfer: 'Transfer bank',
    cheque: 'Cek',
    other: 'Lainnya',
    midtrans: 'Pembayaran online',
};

export default function Show({ invoice, payment }: ShowProps) {
    const inv = invoice.data;
    const { company, errors } = usePage<PageProps & { errors: Record<string, string> }>().props;
    const payForm = useForm({});
    // `processing` only flips on the next render, so a fast double click would post twice.
    const paying = useRef(false);
    const startPayment = () => {
        if (paying.current) {
            return;
        }
        paying.current = true;
        payForm.post(route('portal.invoices.pay', inv.id), {
            preserveScroll: true,
            onFinish: () => {
                paying.current = false;
            },
        });
    };
    const items = inv.items ?? [];
    const payments = inv.payments ?? [];

    // Coming back from the payment page can restore this page from the browser cache with the
    // button still spinning; reload it so the state is the server's again.
    useEffect(() => {
        const reloadIfRestored = (event: PageTransitionEvent) => {
            if (event.persisted) {
                window.location.reload();
            }
        };
        window.addEventListener('pageshow', reloadIfRestored);

        return () => window.removeEventListener('pageshow', reloadIfRestored);
    }, []);

    const discount = Number(inv.discount_amount) > 0;
    const tax = Number(inv.tax_amount) > 0;

    return (
        <PortalLayout title={`Tagihan ${inv.number}`}>
            <PageHeader
                title={`Tagihan ${inv.number}`}
                subtitle={`Terbit ${formatDate(inv.issue_date, company)} · Jatuh tempo ${formatDate(inv.due_date, company)}`}
                actions={
                    <Button
                        asChild
                        variant="outline"
                        size="sm"
                        className="min-h-11"
                        leftIcon={<ArrowLeftIcon className="size-4" aria-hidden="true" />}
                    >
                        <Link href={route('portal.invoices.index')}>Semua tagihan</Link>
                    </Button>
                }
            />

            <Card>
                <CardHeader>
                    <CardTitle>Pembayaran</CardTitle>
                </CardHeader>
                <CardContent className="space-y-4">
                    <div className="space-y-1">
                        <div className="flex flex-wrap items-center justify-between gap-2">
                            <p className="text-sm text-muted-foreground">Sisa yang harus dibayar</p>
                            <PortalInvoiceStatus status={inv.status} />
                        </div>
                        <p className="text-3xl font-semibold tabular-nums">
                            {formatMoney(inv.sisa, company)}
                        </p>
                    </div>

                    <dl className="grid grid-cols-[1fr_auto] gap-x-4 gap-y-2 text-sm">
                        <dt className="text-muted-foreground">Subtotal</dt>
                        <dd className="text-right tabular-nums">
                            {formatMoney(inv.subtotal, company)}
                        </dd>
                        {discount && (
                            <>
                                <dt className="text-muted-foreground">Diskon</dt>
                                <dd className="text-right tabular-nums">
                                    {formatMoney(inv.discount_amount, company)}
                                </dd>
                            </>
                        )}
                        {tax && (
                            <>
                                <dt className="text-muted-foreground">Pajak</dt>
                                <dd className="text-right tabular-nums">
                                    {formatMoney(inv.tax_amount, company)}
                                </dd>
                            </>
                        )}
                        <dt className="font-medium">Total</dt>
                        <dd className="text-right font-medium tabular-nums">
                            {formatMoney(inv.total, company)}
                        </dd>
                        <dt className="text-muted-foreground">Sudah dibayar</dt>
                        <dd className="text-right tabular-nums">
                            {formatMoney(inv.paid_amount, company)}
                        </dd>
                    </dl>

                    <div className="space-y-3 border-t pt-4">
                        {errors.pay && <Alert variant="danger">{errors.pay}</Alert>}
                        {payment.enabled ? (
                            <>
                                <Button
                                    type="button"
                                    size="lg"
                                    className="min-h-11 w-full sm:w-auto"
                                    disabled={!payment.can_pay}
                                    loading={payForm.processing}
                                    aria-describedby="pay-note"
                                    onClick={startPayment}
                                >
                                    {payment.can_pay
                                        ? `Bayar ${formatMoney(inv.sisa, company)}`
                                        : 'Bayar'}
                                </Button>
                                <p id="pay-note" className="text-sm text-muted-foreground">
                                    {payment.can_pay
                                        ? 'Anda akan diarahkan ke halaman pembayaran Midtrans untuk memilih transfer bank, e-wallet, atau kartu. Kembali ke sini setelah selesai.'
                                        : payment.blocked_reason}
                                </p>
                            </>
                        ) : (
                            <p className="text-sm text-muted-foreground">
                                Pembayaran online belum tersedia. Hubungi kami untuk membayar
                                tagihan ini.
                            </p>
                        )}
                    </div>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>Rincian tagihan</CardTitle>
                </CardHeader>
                <CardContent>
                    {items.length === 0 ? (
                        <p className="text-sm text-muted-foreground">Belum ada rincian item.</p>
                    ) : (
                        <ul className="divide-y">
                            {items.map((item) => (
                                <li
                                    key={item.id}
                                    className="flex items-start justify-between gap-4 py-3"
                                >
                                    <div className="min-w-0">
                                        <p className="break-words font-medium">
                                            {item.description}
                                        </p>
                                        <p className="text-sm text-muted-foreground">
                                            {formatNumber(item.quantity)} ×{' '}
                                            {formatMoney(item.unit_price, company)}
                                        </p>
                                    </div>
                                    <p className="shrink-0 font-medium tabular-nums">
                                        {formatMoney(item.line_total, company)}
                                    </p>
                                </li>
                            ))}
                        </ul>
                    )}
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>Riwayat pembayaran</CardTitle>
                </CardHeader>
                <CardContent>
                    {payments.length === 0 ? (
                        <p className="text-sm text-muted-foreground">Belum ada pembayaran.</p>
                    ) : (
                        <ul className="divide-y">
                            {payments.map((row) => (
                                <li
                                    key={row.id}
                                    className="flex items-start justify-between gap-4 py-3"
                                >
                                    <div className="min-w-0">
                                        <p className="font-medium">
                                            {methodLabels[row.method] ?? row.method}
                                        </p>
                                        <p className="text-sm text-muted-foreground">
                                            {formatDateTime(row.paid_at)}
                                        </p>
                                    </div>
                                    <p className="shrink-0 font-medium tabular-nums">
                                        {formatMoney(row.amount, company)}
                                    </p>
                                </li>
                            ))}
                        </ul>
                    )}
                </CardContent>
            </Card>
        </PortalLayout>
    );
}
