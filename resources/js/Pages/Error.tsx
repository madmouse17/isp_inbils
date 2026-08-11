import { Head, Link } from '@inertiajs/react';
import { ShieldAlert } from 'lucide-react';
import { Button } from '@/Components/ui/Button';
import { Alert, AlertDescription, AlertTitle } from '@/Components/ui';
import {
    Empty,
    EmptyContent,
    EmptyDescription,
    EmptyHeader,
    EmptyMedia,
    EmptyTitle,
} from '@/Components/ui/empty';

const copy: Record<number, { title: string; description: string }> = {
    403: {
        title: 'Access denied',
        description: 'You do not have permission to view this page.',
    },
    404: {
        title: 'Page not found',
        description: 'The page you requested does not exist or was moved.',
    },
    500: {
        title: 'Something went wrong',
        description: 'An unexpected error occurred. Try again or contact support.',
    },
    503: {
        title: 'Service unavailable',
        description: 'The application is temporarily unavailable. Please try again later.',
    },
};

export default function ErrorPage({ status }: { status: number }) {
    const { title, description } = copy[status] ?? {
        title: `Error ${status}`,
        description: 'An error occurred while processing your request.',
    };

    const isForbidden = status === 403;

    return (
        <div className="flex min-h-svh items-center justify-center bg-background p-6">
            <Head title={title} />
            <div className="w-full max-w-lg space-y-4">
                {isForbidden ? (
                    <Alert variant="destructive">
                        <ShieldAlert />
                        <AlertTitle>{title}</AlertTitle>
                        <AlertDescription>{description}</AlertDescription>
                    </Alert>
                ) : null}
                <Empty className="border border-dashed">
                    <EmptyHeader>
                        <EmptyMedia variant="icon">
                            <ShieldAlert />
                        </EmptyMedia>
                        <EmptyTitle>
                            {status} — {title}
                        </EmptyTitle>
                        <EmptyDescription>{description}</EmptyDescription>
                    </EmptyHeader>
                    <EmptyContent>
                        <Button asChild variant="outline">
                            <Link href="/">Back to home</Link>
                        </Button>
                    </EmptyContent>
                </Empty>
            </div>
        </div>
    );
}
