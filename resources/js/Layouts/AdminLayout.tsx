import { useState, useEffect } from 'react';
import { Head } from '@inertiajs/react';
import {
    HomeIcon,
    UsersIcon,
    Squares2X2Icon,
    Bars3Icon,
    MoonIcon,
    SunIcon,
} from '@heroicons/react/24/outline';
import { Sidebar, SidebarItem, SidebarSection, Topbar } from '@/Components/ui';

interface AdminLayoutProps {
    title?: string;
    children: React.ReactNode;
}

export default function AdminLayout({ title, children }: AdminLayoutProps) {
    const [sidebarOpen, setSidebarOpen] = useState(false);
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
            <div className="flex h-screen bg-surface-50 dark:bg-surface-950">
                <Sidebar open={sidebarOpen} onClose={() => setSidebarOpen(false)}>
                    <SidebarSection title="Menu">
                        <SidebarItem href="/dashboard" icon={<HomeIcon />} label="Dashboard" />
                        <SidebarItem href="/admin/users" icon={<UsersIcon />} label="Users" />
                        <SidebarItem href="/admin/components" icon={<Squares2X2Icon />} label="Komponen" />
                    </SidebarSection>
                </Sidebar>

                <div className="flex flex-1 flex-col overflow-hidden">
                    <Topbar
                        title={title}
                        left={
                            <button
                                onClick={() => setSidebarOpen(!sidebarOpen)}
                                className="rounded-lg p-2 text-surface-600 hover:bg-surface-100 dark:text-surface-400 dark:hover:bg-surface-800 lg:hidden"
                                aria-label="Toggle sidebar"
                            >
                                <Bars3Icon className="h-5 w-5" />
                            </button>
                        }
                        right={
                            <button
                                onClick={toggleDark}
                                className="rounded-lg p-2 text-surface-600 hover:bg-surface-100 dark:text-surface-400 dark:hover:bg-surface-800"
                                aria-label="Toggle dark mode"
                            >
                                {darkMode ? <SunIcon className="h-5 w-5" /> : <MoonIcon className="h-5 w-5" />}
                            </button>
                        }
                    />
                    <main className="flex-1 overflow-auto p-6">{children}</main>
                </div>
            </div>
        </>
    );
}
