import { Link, usePage } from '@inertiajs/react';
import { Alert, Button, Card, EmptyState } from '@/Components/ui';
import { PageHeader } from '@/Components/composite';
import {
    PortalInvoiceStatus,
    payableStatuses,
} from '@/Components/composite/billing/PortalInvoiceStatus';
import PortalLayout from '@/Layouts/PortalLayout';
import { formatDate, formatMoney } from '@/lib/format';
import type { PageProps, Paginated } from '@/types';
import type { PortalInvoice } from '@/types/portal';

interface IndexProps extends Record<string, unknown> {
    invoices: Paginated<PortalInvoice>;
    paymentEnabled: boolean;
}

export default function Index({ invoices, paymentEnabled }: IndexProps) {
    const { company } = usePage<PageProps>().props;
    const { meta, links } = invoices;

    return (
        <PortalLayout title="Tagihan Saya">
            <PageHeader
                title="Tagihan Saya"
                subtitle="Tagihan yang sudah diterbitkan untuk akun Anda."
            />

            {!paymentEnabled && invoices.data.length > 0 && (
                <Alert variant="info">
                    Pembayaran online belum tersedia. Hubungi kami untuk membayar tagihan.
                </Alert>
            )}

            {invoices.data.length === 0 ? (
                <Card>
                    <EmptyState
                        title="Belum ada tagihan"
                        description="Tagihan muncul di sini setelah diterbitkan oleh petugas kami."
                    />
                </Card>
            ) : (
                <Card>
                    <ul className="divide-y">
                        {invoices.data.map((invoice) => {
                            const payable =
                                paymentEnabled && payableStatuses.includes(invoice.status);

                            return (
                                <li
                                    key={invoice.id}
                                    className="flex flex-wrap items-center justify-between gap-x-6 gap-y-3 p-4"
                                >
                                    <div className="min-w-0 space-y-1">
                                        <Link
                                            href={route('portal.invoices.show', invoice.id)}
                                            className="rounded-sm font-medium hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                        >
                                            {invoice.number}
                                        </Link>
                                        <p className="text-sm text-muted-foreground">
                                            Jatuh tempo {formatDate(invoice.due_date, company)}
                                        </p>
                                    </div>
                                    <div className="flex flex-wrap items-center gap-x-4 gap-y-2">
                                        <div className="min-w-24 sm:text-right">
                                            <p className="text-xs text-muted-foreground">Sisa</p>
                                            <p className="font-semibold tabular-nums">
                                                {formatMoney(invoice.sisa, company)}
                                            </p>
                                        </div>
                                        <PortalInvoiceStatus status={invoice.status} />
                                        <Button
                                            asChild
                                            variant={payable ? 'default' : 'outline'}
                                            size="sm"
                                            className="min-h-11 min-w-20"
                                        >
                                            <Link href={route('portal.invoices.show', invoice.id)}>
                                                {payable ? 'Bayar' : 'Lihat'}
                                                <span className="sr-only">
                                                    {' '}
                                                    tagihan {invoice.number}
                                                </span>
                                            </Link>
                                        </Button>
                                    </div>
                                </li>
                            );
                        })}
                    </ul>
                </Card>
            )}

            {meta.last_page > 1 && (
                <nav
                    aria-label="Halaman tagihan"
                    className="flex flex-wrap items-center justify-between gap-3"
                >
                    <p className="text-sm text-muted-foreground">
                        Halaman {meta.current_page} dari {meta.last_page}
                    </p>
                    <div className="flex items-center gap-2">
                        {links?.prev ? (
                            <Button asChild variant="outline" className="min-h-11">
                                <Link href={links.prev} preserveScroll>
                                    Sebelumnya
                                </Link>
                            </Button>
                        ) : (
                            <Button variant="outline" className="min-h-11" disabled>
                                Sebelumnya
                            </Button>
                        )}
                        {links?.next ? (
                            <Button asChild variant="outline" className="min-h-11">
                                <Link href={links.next} preserveScroll>
                                    Berikutnya
                                </Link>
                            </Button>
                        ) : (
                            <Button variant="outline" className="min-h-11" disabled>
                                Berikutnya
                            </Button>
                        )}
                    </div>
                </nav>
            )}
        </PortalLayout>
    );
}
