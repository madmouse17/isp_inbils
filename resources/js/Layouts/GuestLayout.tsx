import { AuthBackdrop } from '@/Components/composite/AuthBackdrop';
import { ImpersonationBanner } from '@/Components/composite/ImpersonationBanner';
import { BrandLockup } from '@/Components/ui/BrandMark';
import { Link } from '@inertiajs/react';
import type { PropsWithChildren } from 'react';

export default function Guest({ children }: PropsWithChildren) {
    return (
        <>
            <ImpersonationBanner />
            <div className="relative isolate flex min-h-screen flex-col items-center bg-muted/30 px-4 py-6 sm:justify-center dark:bg-background">
                <AuthBackdrop />
                <Link
                    href="/"
                    className="auth-rise rounded-md focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-hidden"
                >
                    <BrandLockup />
                </Link>

                <div className="auth-rise mt-6 w-full overflow-hidden rounded-lg border bg-card px-6 py-4 text-card-foreground [animation-delay:120ms] sm:max-w-md">
                    {children}
                </div>
            </div>
        </>
    );
}
