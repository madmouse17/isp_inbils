import { useState } from 'react';
import { Link, router } from '@inertiajs/react';
import {
    CheckCircleIcon,
    ClockIcon,
    ExclamationTriangleIcon,
    XCircleIcon,
} from '@heroicons/react/24/outline';
import { Button, Card, CardContent, EmptyState } from '@/Components/ui';
import { PageHeader } from '@/Components/composite';
import PortalLayout from '@/Layouts/PortalLayout';
import { formatDateTime, formatMoney } from '@/lib/format';
import type { PaymentAttemptStatus, PortalPaymentAttempt } from '@/types/portal';

interface ReturnProps extends Record<string, unknown> {
    attempt: PortalPaymentAttempt | null;
}

const outcomes: Record<
    PaymentAttemptStatus,
    { title: string; body: string; Icon: typeof CheckCircleIcon; tone: string }
> = {
    paid: {
        title: 'Pembayaran diterima',
        body: 'Terima kasih. Pembayaran Anda sudah tercatat pada tagihan.',
        Icon: CheckCircleIcon,
        tone: 'text-emerald-700 dark:text-emerald-400',
    },
    pending: {
        title: 'Menunggu konfirmasi',
        body: 'Pembayaran belum dikonfirmasi. Biasanya selesai dalam beberapa menit; transfer bank bisa lebih lama. Periksa lagi sebentar lagi.',
        Icon: ClockIcon,
        tone: 'text-amber-700 dark:text-amber-400',
    },
    failed: {
        title: 'Pembayaran gagal atau dibatalkan',
        body: 'Tidak ada dana yang diterima. Anda dapat mencoba membayar lagi dari halaman tagihan.',
        Icon: XCircleIcon,
        tone: 'text-destructive',
    },
    expired: {
        title: 'Halaman pembayaran kedaluwarsa',
        body: 'Batas waktu pembayaran sudah lewat. Buka tagihan untuk membuat halaman pembayaran baru.',
        Icon: XCircleIcon,
        tone: 'text-destructive',
    },
    exception: {
        title: 'Pembayaran sedang diperiksa',
        body: 'Petugas kami sedang memeriksa pembayaran ini. Anda tidak perlu membayar ulang. Hubungi kami jika ada pertanyaan.',
        Icon: ExclamationTriangleIcon,
        tone: 'text-amber-700 dark:text-amber-400',
    },
};

export default function Return({ attempt }: ReturnProps) {
    const [checking, setChecking] = useState(false);
    const outcome = attempt === null ? null : outcomes[attempt.status];

    return (
        <PortalLayout title="Status pembayaran">
            <PageHeader
                title="Status pembayaran"
                subtitle="Status pembayaran terakhir yang tercatat di sistem kami."
            />

            {attempt === null || outcome === null ? (
                <Card>
                    <EmptyState
                        title="Tidak ada pembayaran yang ditemukan"
                        description="Mulai pembayaran dari halaman tagihan. Statusnya akan tampil di sini."
                        action={
                            <Button asChild className="min-h-11">
                                <Link href={route('portal.invoices.index')}>Lihat tagihan</Link>
                            </Button>
                        }
                    />
                </Card>
            ) : (
                <Card>
                    <CardContent className="space-y-4 pt-6">
                        <div role="status" className="flex items-start gap-3">
                            <outcome.Icon
                                className={`mt-0.5 size-7 shrink-0 ${outcome.tone}`}
                                aria-hidden="true"
                            />
                            <div className="space-y-1">
                                <h2 className="text-lg font-semibold">{outcome.title}</h2>
                                <p className="text-sm text-muted-foreground">{outcome.body}</p>
                            </div>
                        </div>

                        <dl className="grid grid-cols-[1fr_auto] gap-x-4 gap-y-2 border-t pt-4 text-sm">
                            <dt className="text-muted-foreground">Tagihan</dt>
                            <dd className="text-right font-medium">{attempt.invoice.number}</dd>
                            <dt className="text-muted-foreground">Jumlah</dt>
                            <dd className="text-right tabular-nums">
                                {formatMoney(attempt.amount)}
                            </dd>
                            {attempt.settled_at && (
                                <>
                                    <dt className="text-muted-foreground">Diterima</dt>
                                    <dd className="text-right">
                                        {formatDateTime(attempt.settled_at)}
                                    </dd>
                                </>
                            )}
                        </dl>

                        <div className="flex flex-wrap gap-2">
                            {attempt.status === 'pending' && (
                                <Button
                                    type="button"
                                    className="min-h-11"
                                    loading={checking}
                                    onClick={() =>
                                        router.reload({
                                            only: ['attempt'],
                                            onStart: () => setChecking(true),
                                            onFinish: () => setChecking(false),
                                        })
                                    }
                                >
                                    Periksa status
                                </Button>
                            )}
                            <Button
                                asChild
                                variant={attempt.status === 'pending' ? 'outline' : 'default'}
                                className="min-h-11"
                            >
                                <Link href={route('portal.invoices.show', attempt.invoice.id)}>
                                    Buka tagihan
                                </Link>
                            </Button>
                        </div>
                    </CardContent>
                </Card>
            )}
        </PortalLayout>
    );
}
