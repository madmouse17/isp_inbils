import { Head } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { Button } from '@/Components/ui';

interface SetupLayoutProps {
    title?: string;
    children: React.ReactNode;
}

export default function SetupLayout({ title = 'Setup', children }: SetupLayoutProps) {
    const [darkMode, setDarkMode] = useState(false);

    useEffect(() => {
        const stored = localStorage.getItem('darkMode') === 'true';
        const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
        const enabled = stored || prefersDark;
        setDarkMode(enabled);
        document.documentElement.classList.toggle('dark', enabled);
    }, []);

    const toggleDark = () => {
        const enabled = !darkMode;
        setDarkMode(enabled);
        localStorage.setItem('darkMode', String(enabled));
        document.documentElement.classList.toggle('dark', enabled);
    };

    return (
        <>
            <Head title={title} />
            <main className="min-h-screen bg-muted/30 px-4 py-8 text-foreground dark:bg-background sm:px-6 lg:px-8">
                <div className="mx-auto flex max-w-3xl flex-col gap-6">
                    <header className="flex items-center justify-between">
                        <div className="flex items-center gap-3">
                            <div className="flex h-11 w-11 items-center justify-center rounded-xl bg-primary text-base font-bold text-primary-foreground">
                                IN
                            </div>
                            <div>
                                <p className="text-sm font-medium text-muted-foreground">inbils</p>
                                <h1 className="text-xl font-semibold">Initial Setup</h1>
                            </div>
                        </div>
                        <Button type="button" variant="secondary" onClick={toggleDark}>
                            {darkMode ? 'Light' : 'Dark'}
                        </Button>
                    </header>

                    {children}
                </div>
            </main>
        </>
    );
}
