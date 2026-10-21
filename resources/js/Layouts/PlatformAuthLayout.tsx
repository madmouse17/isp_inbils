import { Head, Link, router, usePage } from '@inertiajs/react';
import { LogOut } from 'lucide-react';
import { AuthBackdrop } from '@/Components/composite/AuthBackdrop';
import { OperatorBand } from '@/Components/composite/platform/OperatorBand';
import { Button, Wordmark } from '@/Components/ui';
import { cn } from '@/lib/utils';
import type { PlatformSharedProps } from '@/types/platform';

interface PlatformAuthLayoutProps {
    title: string;
    children: React.ReactNode;
    wide?: boolean;
}

export default function PlatformAuthLayout({
    title,
    children,
    wide = false,
}: PlatformAuthLayoutProps) {
    const admin = (usePage().props as unknown as PlatformSharedProps).platform.admin;

    return (
        <>
            <Head title={title} />
            <main className="relative isolate flex min-h-screen flex-col bg-muted/30 text-foreground dark:bg-background">
                <AuthBackdrop />
                <OperatorBand />
                <div className="flex flex-1 items-center justify-center px-4 py-8 sm:px-6">
                    <div className={cn('w-full', wide ? 'max-w-3xl' : 'max-w-md')}>
                        <div className="mb-5 flex items-center justify-between gap-4">
                            <div>
                                <Wordmark className="h-10" />
                                <p className="mt-1 text-sm text-muted-foreground">
                                    Akun operator Inbils
                                </p>
                            </div>
                            {admin ? (
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    leftIcon={<LogOut className="size-4" aria-hidden="true" />}
                                    onClick={() => router.post(route('platform.logout'))}
                                >
                                    Keluar
                                </Button>
                            ) : null}
                        </div>
                        <div className="auth-rise rounded-lg border bg-card p-6 text-card-foreground sm:p-8">
                            {children}
                        </div>
                        {admin?.two_factor_enabled ? (
                            <div className="mt-4 text-center">
                                <Link
                                    href={route('platform.dashboard')}
                                    className="inline-flex min-h-11 items-center rounded-md text-sm font-medium text-primary-text underline-offset-4 hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-hidden"
                                >
                                    Kembali ke dasbor
                                </Link>
                            </div>
                        ) : null}
                    </div>
                </div>
            </main>
        </>
    );
}
