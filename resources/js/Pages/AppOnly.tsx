import { Head, Link, router } from '@inertiajs/react';
import { Button } from '@/Components/ui';
import GuestLayout from '@/Layouts/GuestLayout';

type AppOnlyRole = 'technician' | 'customer';

interface AppOnlyProps extends Record<string, unknown> {
    /** The app this login uses; null when it holds neither the technician nor the customer marker. */
    role: AppOnlyRole | null;
    appName: string | null;
    playStoreUrl: string | null;
}

const roleLabels: Record<AppOnlyRole, string> = {
    technician: 'teknisi',
    customer: 'pelanggan',
};

/** Where logins without web access land: a technician or customer works in the Android app, anyone else is told to ask for web access. */
export default function AppOnly({ role, appName, playStoreUrl }: AppOnlyProps) {
    if (role === null) {
        return (
            <GuestLayout>
                <Head title="Akun ini belum punya akses aplikasi web" />
                <main lang="id" className="space-y-5 py-2">
                    <header className="space-y-2">
                        <h1 className="text-xl font-semibold tracking-tight text-foreground sm:text-2xl">
                            Akun ini belum punya akses aplikasi web
                        </h1>
                        <p className="text-sm leading-relaxed text-muted-foreground">
                            Minta admin perusahaan Anda memberi peran yang memuat akses web.
                        </p>
                    </header>

                    <div className="flex flex-col gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            className="min-h-11"
                            onClick={() => router.post(route('logout'))}
                        >
                            Keluar
                        </Button>
                    </div>
                </main>
            </GuestLayout>
        );
    }

    return (
        <GuestLayout>
            <Head title="Gunakan aplikasi Inbils" />
            <main lang="id" className="space-y-5 py-2">
                <header className="space-y-2">
                    <h1 className="text-xl font-semibold tracking-tight text-foreground sm:text-2xl">
                        Gunakan aplikasi Inbils
                    </h1>
                    <p className="text-sm leading-relaxed text-muted-foreground">
                        Akun {roleLabels[role]} dipakai lewat aplikasi Android {appName}. Masuk di
                        aplikasi dengan email dan kata sandi yang sama.
                    </p>
                </header>

                <div className="flex flex-col gap-2">
                    {playStoreUrl ? (
                        <Button asChild className="min-h-11">
                            <a href={playStoreUrl} target="_blank" rel="noopener noreferrer">
                                Buka Play Store
                            </a>
                        </Button>
                    ) : (
                        <p className="rounded-md border border-border bg-muted/30 px-3 py-3 text-sm text-muted-foreground">
                            Minta tautan aplikasi ke admin ISP Anda.
                        </p>
                    )}
                    <Button asChild variant="outline" className="min-h-11">
                        <Link href={route('profile.edit')}>Kelola akun &amp; 2FA</Link>
                    </Button>
                    <Button
                        type="button"
                        variant="outline"
                        className="min-h-11"
                        onClick={() => router.post(route('logout'))}
                    >
                        Keluar
                    </Button>
                </div>
            </main>
        </GuestLayout>
    );
}
