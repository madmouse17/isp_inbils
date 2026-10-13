import { Wordmark } from '@/Components/ui/Wordmark';
import { Link } from '@inertiajs/react';
import type { PropsWithChildren } from 'react';

export default function Guest({ children }: PropsWithChildren) {
    return (
        <div className="flex min-h-screen flex-col items-center bg-muted/30 px-4 py-6 sm:justify-center dark:bg-background">
            <Link
                href="/"
                className="rounded-md text-3xl focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-hidden"
            >
                <Wordmark />
            </Link>

            <div className="mt-6 w-full overflow-hidden rounded-lg border bg-card px-6 py-4 text-card-foreground sm:max-w-md">
                {children}
            </div>
        </div>
    );
}
