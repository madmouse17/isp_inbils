import { useEffect, useState } from 'react';
import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    ArrowRightStartOnRectangleIcon,
    ChevronDownIcon,
    KeyIcon,
    MoonIcon,
    SunIcon,
    UserCircleIcon,
} from '@heroicons/react/24/outline';
import type { PageProps } from '@/types';
import { Button, Dropdown, DropdownItem, DropdownSeparator } from '@/Components/ui';
import { useToast } from '@/hooks/useToast';

interface PortalLayoutProps {
    title: string;
    children: React.ReactNode;
}

/** The customer portal shell: a header and a single column, no admin sidebar. */
export default function PortalLayout({ title, children }: PortalLayoutProps) {
    const { props, url } = usePage<PageProps>();
    const user = props.auth.user;
    const [darkMode, setDarkMode] = useState(() => {
        if (typeof window === 'undefined') {
            return false;
        }

        return (
            localStorage.getItem('darkMode') === 'true' ||
            window.matchMedia('(prefers-color-scheme: dark)').matches
        );
    });
    useToast();

    useEffect(() => {
        document.documentElement.classList.toggle('dark', darkMode);
    }, [darkMode]);

    const toggleDark = () => {
        const enabled = !darkMode;
        setDarkMode(enabled);
        localStorage.setItem('darkMode', String(enabled));
    };

    const invoicesActive = url.startsWith('/portal/invoices') || url.startsWith('/portal/payments');

    return (
        <>
            <Head title={title} />
            <a
                href="#portal-content"
                className="sr-only focus-visible:not-sr-only focus-visible:fixed focus-visible:top-2 focus-visible:left-2 focus-visible:z-50 focus-visible:rounded-md focus-visible:bg-background focus-visible:px-3 focus-visible:py-2 focus-visible:text-sm focus-visible:ring-2 focus-visible:ring-ring"
            >
                Lewati ke konten
            </a>
            <div className="min-h-screen bg-muted/30 text-foreground dark:bg-background">
                <header className="border-b bg-background">
                    <div className="mx-auto flex min-h-14 w-full max-w-4xl items-center justify-between gap-3 px-4">
                        <div className="flex min-w-0 items-center gap-2 sm:gap-6">
                            <span className="truncate text-sm font-semibold">
                                {props.company?.name ?? props.app.name}
                            </span>
                            <nav aria-label="Portal pelanggan" className="flex items-center gap-1">
                                <Button
                                    asChild
                                    variant={invoicesActive ? 'secondary' : 'ghost'}
                                    size="sm"
                                    className="min-h-11"
                                >
                                    <Link
                                        href={route('portal.invoices.index')}
                                        aria-current={invoicesActive ? 'page' : undefined}
                                    >
                                        Tagihan
                                    </Link>
                                </Button>
                                <Button asChild variant="ghost" size="sm" className="min-h-11">
                                    <Link href={route('admin.dashboard')}>Dasbor</Link>
                                </Button>
                            </nav>
                        </div>
                        {user && (
                            <Dropdown
                                trigger={
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="sm"
                                        className="min-h-11 shrink-0 gap-2"
                                        aria-label="Menu akun"
                                    >
                                        <UserCircleIcon className="size-5" aria-hidden="true" />
                                        <span className="hidden max-w-36 truncate sm:inline">
                                            {user.name}
                                        </span>
                                        <ChevronDownIcon
                                            className="size-4 text-muted-foreground"
                                            aria-hidden="true"
                                        />
                                    </Button>
                                }
                            >
                                <div className="px-3 py-2">
                                    <p className="truncate text-sm font-semibold">{user.name}</p>
                                    <p className="truncate text-xs text-muted-foreground">
                                        {user.email}
                                    </p>
                                </div>
                                <DropdownSeparator />
                                <DropdownItem onClick={() => router.get(route('profile.edit'))}>
                                    <KeyIcon className="size-4" aria-hidden="true" />
                                    Ganti kata sandi
                                </DropdownItem>
                                <DropdownItem onClick={toggleDark}>
                                    {darkMode ? (
                                        <SunIcon className="size-4" aria-hidden="true" />
                                    ) : (
                                        <MoonIcon className="size-4" aria-hidden="true" />
                                    )}
                                    {darkMode ? 'Mode terang' : 'Mode gelap'}
                                </DropdownItem>
                                <DropdownSeparator />
                                <DropdownItem
                                    onClick={() => router.post(route('logout'))}
                                    destructive
                                >
                                    <ArrowRightStartOnRectangleIcon
                                        className="size-4"
                                        aria-hidden="true"
                                    />
                                    Keluar
                                </DropdownItem>
                            </Dropdown>
                        )}
                    </div>
                </header>
                <main
                    id="portal-content"
                    tabIndex={-1}
                    className="mx-auto space-y-6 w-full max-w-4xl px-4 py-6 focus:outline-hidden sm:py-8"
                >
                    {children}
                </main>
            </div>
        </>
    );
}
