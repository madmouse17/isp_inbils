import { useState } from 'react';
import { Head, router } from '@inertiajs/react';
import { AlertTriangle, CheckCircle2, Clock, XCircle, type LucideIcon } from 'lucide-react';
import { Button } from '@/Components/ui';
import GuestLayout from '@/Layouts/GuestLayout';
import { useCompany } from '@/hooks/useCompany';
import { formatDateTime, formatRupiah } from '@/lib/format';
import type { PaymentAttemptStatus, PortalPaymentAttempt } from '@/types/portal';

interface ReturnProps extends Record<string, unknown> {
    attempt: PortalPaymentAttempt | null;
}

// The icon colours are the --status-* tokens (a dark value each); the icon is decoration, so 3:1 is enough.
const outcomes: Record<
    PaymentAttemptStatus,
    { title: string; body: string; Icon: LucideIcon; tone: string }
> = {
    paid: {
        title: 'Pembayaran diterima',
        body: 'Terima kasih. Pembayaran Anda sudah tercatat pada tagihan.',
        Icon: CheckCircle2,
        tone: 'text-(--status-success)',
    },
    pending: {
        title: 'Menunggu konfirmasi',
        body: 'Pembayaran belum dikonfirmasi. Biasanya selesai dalam beberapa menit; transfer bank bisa lebih lama. Periksa lagi sebentar lagi.',
        Icon: Clock,
        tone: 'text-(--status-warning)',
    },
    failed: {
        title: 'Pembayaran gagal atau dibatalkan',
        body: 'Tidak ada dana yang diterima. Anda dapat mencoba membayar lagi dari aplikasi Inbils.',
        Icon: XCircle,
        tone: 'text-destructive-text',
    },
    expired: {
        title: 'Halaman pembayaran kedaluwarsa',
        body: 'Batas waktu pembayaran sudah lewat. Buka tagihan di aplikasi Inbils untuk membuat halaman pembayaran baru.',
        Icon: XCircle,
        tone: 'text-destructive-text',
    },
    exception: {
        title: 'Pembayaran sedang diperiksa',
        body: 'Pembayaran ini sedang diperiksa petugas. Anda tidak perlu membayar ulang. Hubungi penyedia layanan Anda jika ada pertanyaan.',
        Icon: AlertTriangle,
        tone: 'text-(--status-warning)',
    },
};

export default function Return({ attempt }: ReturnProps) {
    const company = useCompany();
    const [checking, setChecking] = useState(false);
    const outcome = attempt === null ? null : outcomes[attempt.status];

    return (
        <GuestLayout>
            <Head title="Status pembayaran" />
            <main lang="id" className="space-y-5">
                <header className="space-y-2">
                    <h1 className="text-xl font-semibold tracking-tight text-foreground sm:text-2xl">
                        Status pembayaran
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        Status pembayaran terakhir yang tercatat di sistem kami.
                    </p>
                </header>

                {attempt === null || outcome === null ? (
                    <div role="status" className="space-y-1">
                        <h2 className="text-lg font-semibold">
                            Tidak ada pembayaran yang ditemukan
                        </h2>
                        <p className="text-sm text-muted-foreground">
                            Mulai pembayaran dari tagihan di aplikasi Inbils. Statusnya akan tampil
                            di sini.
                        </p>
                    </div>
                ) : (
                    <>
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
                                {formatRupiah(attempt.amount, company)}
                            </dd>
                            {attempt.settled_at && (
                                <>
                                    <dt className="text-muted-foreground">Diterima</dt>
                                    <dd className="text-right">
                                        {formatDateTime(attempt.settled_at, company)}
                                    </dd>
                                </>
                            )}
                        </dl>

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

                        <p className="text-sm text-muted-foreground">
                            Kembali ke aplikasi Inbils untuk melihat tagihan Anda.
                        </p>
                    </>
                )}
            </main>
        </GuestLayout>
    );
}
