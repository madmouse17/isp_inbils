import { Head } from '@inertiajs/react';
import { Button } from '@/Components/ui';
import { Wordmark } from '@/Components/ui/Wordmark';
import { useTheme } from '@/hooks/useTheme';

interface SetupLayoutProps {
    title?: string;
    children: React.ReactNode;
}

export default function SetupLayout({ title = 'Pengaturan awal', children }: SetupLayoutProps) {
    const { dark, toggle } = useTheme();

    return (
        <>
            <Head title={title} />
            <main className="min-h-screen bg-muted/30 px-4 py-8 text-foreground sm:px-6 lg:px-8 dark:bg-background">
                <div className="mx-auto flex max-w-3xl flex-col gap-6">
                    <header className="flex items-center justify-between gap-4">
                        <div>
                            <Wordmark className="text-sm text-muted-foreground" />
                            <h1 className="text-xl font-semibold">Pengaturan awal</h1>
                        </div>
                        <Button type="button" variant="secondary" onClick={toggle}>
                            {dark ? 'Mode terang' : 'Mode gelap'}
                        </Button>
                    </header>

                    {children}
                </div>
            </main>
        </>
    );
}
