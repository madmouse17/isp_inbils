import { useState, useEffect } from 'react';
import { Head, Link } from '@inertiajs/react';
import {
    BanknotesIcon,
    BriefcaseIcon,
    BuildingOfficeIcon,
    ChatBubbleLeftRightIcon,
    ClipboardDocumentListIcon,
    Cog6ToothIcon,
    HomeIcon,
    KeyIcon,
    ShieldCheckIcon,
    Squares2X2Icon,
    TagIcon,
    UsersIcon,
    Bars3Icon,
    MoonIcon,
    SunIcon,
} from '@heroicons/react/24/outline';
import { Button, Sidebar, SidebarItem, SidebarSection, Topbar } from '@/Components/ui';
import { useCompany } from '@/hooks/useCompany';
import { usePermission } from '@/hooks/usePermission';
import { useToast } from '@/hooks/useToast';

interface AdminLayoutProps {
    title?: string;
    children: React.ReactNode;
}

export default function AdminLayout({ title, children }: AdminLayoutProps) {
    const [sidebarOpen, setSidebarOpen] = useState(false);
    const [darkMode, setDarkMode] = useState(false);
    const company = useCompany();
    const { can, canAny } = usePermission();
    useToast();

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
                        <SidebarItem href="/admin/dashboard" icon={<HomeIcon />} label="Dashboard" />
                        {can('company.manage') && <SidebarItem href="/admin/company/profile" icon={<BuildingOfficeIcon />} label="Company" />}
                        {canAny(['users.manage']) && <SidebarItem href="/admin/users" icon={<UsersIcon />} label="Users" />}
                        {can('roles.manage') && <SidebarItem href="/admin/roles" icon={<ShieldCheckIcon />} label="Roles" />}
                        {can('users.manage') && <SidebarItem href="/admin/permissions" icon={<KeyIcon />} label="Permissions" />}
                        {can('customer.view') && <SidebarItem href="/admin/customers" icon={<BriefcaseIcon />} label="Customers" />}
                        {can('service.view') && <SidebarItem href="/admin/service-packages" icon={<TagIcon />} label="Service" />}
                        {can('inventory.view') && <SidebarItem href="/admin/inventory" icon={<TagIcon />} label="Inventory" />}
                        {can('spk.view') && <SidebarItem href="/admin/spk" icon={<ClipboardDocumentListIcon />} label="SPK" />}
                        {can('billing.view') && <SidebarItem href="/admin/billing" icon={<BanknotesIcon />} label="Billing" />}
                        {can('ticket.view') && <SidebarItem href="/admin/tickets" icon={<ChatBubbleLeftRightIcon />} label="Ticketing" />}
                        <SidebarItem href="/admin/components" icon={<Squares2X2Icon />} label="Komponen" />
                    </SidebarSection>
                </Sidebar>

                <div className="flex flex-1 flex-col overflow-hidden">
                    <Topbar
                        title={title}
                        left={
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                onClick={() => setSidebarOpen(!sidebarOpen)}
                                className="lg:hidden"
                                aria-label="Toggle sidebar"
                            >
                                <Bars3Icon className="h-5 w-5" />
                            </Button>
                        }
                        right={
                            <>
                                {company && (
                                    <Link
                                        href="/admin/company/profile"
                                        className="hidden items-center gap-2 rounded-lg px-3 py-2 text-sm font-medium text-surface-700 hover:bg-surface-100 dark:text-surface-300 dark:hover:bg-surface-800 sm:flex"
                                    >
                                        <Cog6ToothIcon className="h-4 w-4" />
                                        <span>{company.name}</span>
                                    </Link>
                                )}
                                <Button variant="ghost" size="sm" onClick={toggleDark} aria-label="Toggle dark mode">
                                    {darkMode ? <SunIcon className="h-5 w-5" /> : <MoonIcon className="h-5 w-5" />}
                                </Button>
                            </>
                        }
                    />
                    <main className="flex-1 overflow-auto p-6">{children}</main>
                </div>
            </div>
        </>
    );
}
