import { useEffect } from 'react';
import { Head } from '@inertiajs/react';
import { ClockIcon } from '@heroicons/react/24/outline';
import GuestLayout from '@/Layouts/GuestLayout';

export default function Finished() {
    // GuestLayout does not apply the saved or system theme itself.
    useEffect(() => {
        document.documentElement.classList.toggle(
            'dark',
            localStorage.getItem('darkMode') === 'true' ||
                window.matchMedia('(prefers-color-scheme: dark)').matches,
        );
    }, []);

    return (
        <GuestLayout>
            <Head title="Pembayaran sedang diproses" />
            <main lang="id" className="space-y-5 text-center">
                <div
                    className="mx-auto flex size-14 items-center justify-center rounded-full bg-primary/10 text-primary"
                    aria-hidden="true"
                >
                    <ClockIcon className="size-7" />
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
