import { Head } from '@inertiajs/react';
import { Clock } from 'lucide-react';
import GuestLayout from '@/Layouts/GuestLayout';

export default function Finished() {
    return (
        <GuestLayout>
            <Head title="Pembayaran sedang diproses" />
            <main lang="id" className="space-y-5 text-center">
                <div
                    className="mx-auto flex size-14 items-center justify-center rounded-full bg-primary/10 text-primary-text"
                    aria-hidden="true"
                >
                    <Clock className="size-7" />
                </div>

                <header className="space-y-2">
                    <h1 className="text-xl font-semibold tracking-tight text-foreground sm:text-2xl">
                        Pembayaran sedang diproses
                    </h1>
                    <p className="text-sm leading-relaxed text-muted-foreground">
                        Silakan kembali ke aplikasi Inbils untuk melihat status tagihan.
                    </p>
                </header>
            </main>
        </GuestLayout>
    );
}
