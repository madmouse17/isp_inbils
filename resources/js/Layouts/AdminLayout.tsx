import { useState, useEffect } from 'react';
import { Head, Link, usePage } from '@inertiajs/react';
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
    ServerStackIcon,
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
    const { url } = usePage();
    const company = useCompany();
    const { can, canAny } = usePermission();
    useToast();

    const isActive = (href: string) => url === href || url.startsWith(`${href}/`) || url.startsWith(`${href}?`);

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
            <div className="flex min-h-screen bg-muted/30 text-foreground dark:bg-background">
                <Sidebar open={sidebarOpen} onClose={() => setSidebarOpen(false)}>
                    <SidebarSection title="Menu">
                        <SidebarItem href="/admin/dashboard" icon={<HomeIcon />} label="Dashboard" active={isActive('/admin/dashboard')} />
                        {can('organization.view') && <SidebarItem href="/admin/organizations" icon={<BuildingOfficeIcon />} label="Organization" active={isActive('/admin/organizations')} />}
                        {can('company.manage') && <SidebarItem href="/admin/company/profile" icon={<BuildingOfficeIcon />} label="Company" active={isActive('/admin/company/profile')} />}
                        {canAny(['users.manage']) && <SidebarItem href="/admin/users" icon={<UsersIcon />} label="Users" active={isActive('/admin/users')} />}
                        {can('roles.manage') && <SidebarItem href="/admin/roles" icon={<ShieldCheckIcon />} label="Roles" active={isActive('/admin/roles')} />}
                        {can('users.manage') && <SidebarItem href="/admin/permissions" icon={<KeyIcon />} label="Permissions" active={isActive('/admin/permissions')} />}
                        {can('customer.view') && <SidebarItem href="/admin/customers" icon={<BriefcaseIcon />} label="Customers" active={isActive('/admin/customers')} />}
                        {can('service.view') && <SidebarItem href="/admin/service-packages" icon={<TagIcon />} label="Service" active={isActive('/admin/service-packages')} />}
                        {can('inventory.view') && <SidebarItem href="/admin/products" icon={<TagIcon />} label="Inventory" active={isActive('/admin/products')} />}
                        {can('network_asset.view') && <SidebarItem href="/admin/network-assets" icon={<ServerStackIcon />} label="Network Assets" active={isActive('/admin/network-assets')} />}
                        {can('spk.view') && <SidebarItem href="/admin/spk" icon={<ClipboardDocumentListIcon />} label="SPK" active={isActive('/admin/spk')} />}
                        {can('billing.view') && <SidebarItem href="/admin/invoices" icon={<BanknotesIcon />} label="Billing" active={isActive('/admin/invoices')} />}
                        {can('billing.view') && <SidebarItem href="/admin/billing/receivables" icon={<BanknotesIcon />} label="Tunggakan" active={isActive('/admin/billing/receivables')} />}
                        {can('ticket.view') && <SidebarItem href="/admin/tickets" icon={<ChatBubbleLeftRightIcon />} label="Ticketing" active={isActive('/admin/tickets')} />}
                        <SidebarItem href="/admin/components" icon={<Squares2X2Icon />} label="Komponen" active={isActive('/admin/components')} />
                    </SidebarSection>
                </Sidebar>

                <div className="flex min-w-0 flex-1 flex-col">
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
                                        className="hidden items-center gap-2 rounded-lg px-3 py-2 text-sm font-medium text-muted-foreground hover:bg-accent hover:text-accent-foreground sm:flex"
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
                    <main className="flex-1 overflow-auto p-4 md:p-6">
                        <div className="mx-auto w-full max-w-[1600px] space-y-6">{children}</div>
                    </main>
                </div>
            </div>
        </>
    );
}
