import ApplicationLogo from '@/Components/ApplicationLogo';
import { Link } from '@inertiajs/react';
import { PropsWithChildren } from 'react';

export default function Guest({ children }: PropsWithChildren) {
    return (
        <div className="flex min-h-screen flex-col items-center bg-muted/30 pt-6 sm:justify-center sm:pt-0 dark:bg-background">
            <div>
                <Link href="/">
                    <ApplicationLogo className="h-20 w-20 fill-current text-muted-foreground" />
                </Link>
            </div>

            <div className="mt-6 w-full overflow-hidden bg-card px-6 py-4 text-card-foreground shadow-md sm:max-w-md sm:rounded-lg">
                {children}
            </div>
        </div>
    );
}
