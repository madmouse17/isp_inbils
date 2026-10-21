import { Head } from '@inertiajs/react';
import { CheckCircle2, Clock, XCircle, type LucideIcon } from 'lucide-react';
import { Button } from '@/Components/ui';
import GuestLayout from '@/Layouts/GuestLayout';

type Outcome = 'success' | 'pending' | 'failed' | 'unknown';

interface FinishedProps extends Record<string, unknown> {
    /** What the address says Midtrans reported, as one of four sentences. Display only: the invoice in the app is the state. */
    outcome: Outcome;
    /** The customer app's Play Store listing; null until the platform has set one. */
    appUrl: string | null;
}

/** Opens the customer app on Android; with the app missing, Chrome sends the phone to its Play Store page. */
const CUSTOMER_APP_INTENT =
    'intent:#Intent;action=android.intent.action.MAIN;category=android.intent.category.LAUNCHER;package=id.inbils.customer;end';

// The icon colours are the --status-* tokens (a dark value each); the icon is decoration, so 3:1 is enough.
const outcomes: Record<Outcome, { title: string; body: string; Icon: LucideIcon; tone: string }> = {
    success: {
        title: 'Pembayaran berhasil',
        body: 'Tagihan ditandai lunas begitu Midtrans mengonfirmasi pembayaran. Lihat statusnya di aplikasi Inbils.',
        Icon: CheckCircle2,
        tone: 'text-(--status-success)',
    },
    pending: {
        title: 'Menunggu pembayaran',
        body: 'Selesaikan pembayaran lewat metode yang Anda pilih. Tagihan ditandai lunas setelah pembayaran diterima.',
        Icon: Clock,
        tone: 'text-(--status-warning)',
    },
    failed: {
        title: 'Pembayaran tidak berhasil',
        body: 'Coba lagi dari aplikasi.',
        Icon: XCircle,
        tone: 'text-destructive-text',
    },
    unknown: {
        title: 'Pembayaran sedang diproses',
        body: 'Silakan kembali ke aplikasi Inbils untuk melihat status tagihan.',
        Icon: Clock,
        tone: 'text-(--status-warning)',
    },
};

export default function Finished({ outcome, appUrl }: FinishedProps) {
    const { title, body, Icon, tone } = outcomes[outcome];
    // The Play Store listing the owner set, else the Android intent (a link that does nothing is no use on a desktop).
    const openAppUrl =
        appUrl ?? (/Android/i.test(window.navigator.userAgent) ? CUSTOMER_APP_INTENT : null);

    return (
        <GuestLayout>
            <Head title={title} />
            <main lang="id" className="space-y-5 text-center">
                <Icon className={`mx-auto size-14 ${tone}`} aria-hidden="true" />

                <header className="space-y-2">
                    <h1 className="text-xl font-semibold tracking-tight text-foreground sm:text-2xl">
                        {title}
                    </h1>
                    <p className="text-sm leading-relaxed text-muted-foreground">{body}</p>
                </header>

                {openAppUrl && (
                    <Button asChild className="min-h-11 w-full">
                        <a
                            href={openAppUrl}
                            {...(appUrl ? { target: '_blank', rel: 'noopener noreferrer' } : {})}
                        >
                            Buka aplikasi Inbils
                        </a>
                    </Button>
                )}
            </main>
        </GuestLayout>
    );
}
