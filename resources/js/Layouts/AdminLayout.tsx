import { useState, useEffect } from 'react';
import { Head, Link, router, usePage } from '@inertiajs/react';
import type { PageProps } from '@/types';
import {
    ArchiveBoxIcon,
    ArrowRightStartOnRectangleIcon,
    BanknotesIcon,
    BriefcaseIcon,
    BuildingOfficeIcon,
    ChartBarIcon,
    ChatBubbleLeftRightIcon,
    ClipboardDocumentIcon,
    ClipboardDocumentListIcon,
    Cog6ToothIcon,
    HomeIcon,
    KeyIcon,
    MapPinIcon,
    ShieldCheckIcon,
    Squares2X2Icon,
    ServerStackIcon,
    TagIcon,
    TruckIcon,
    UserCircleIcon,
    UsersIcon,
    Bars3Icon,
    MoonIcon,
    SunIcon,
    WrenchScrewdriverIcon,
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
    const [darkMode, setDarkMode] = useState(() => {
        if (typeof window === 'undefined') {
            return false;
        }

        return (
            localStorage.getItem('darkMode') === 'true' ||
            window.matchMedia('(prefers-color-scheme: dark)').matches
        );
    });
    const { url, props } = usePage<PageProps>();
    const user = props.auth.user;
    const company = useCompany();
    const { can, canAny } = usePermission();
    useToast();

    const isActive = (href: string) =>
        url === href || url.startsWith(`${href}/`) || url.startsWith(`${href}?`);

    useEffect(() => {
        document.documentElement.classList.toggle('dark', darkMode);
    }, [darkMode]);

    const toggleDark = () => {
        const enabled = !darkMode;
        setDarkMode(enabled);
        localStorage.setItem('darkMode', String(enabled));
    };

    const logout = () => router.post(route('logout'));

    return (
        <>
            <Head title={title} />
            <div className="flex min-h-screen bg-muted/30 text-foreground dark:bg-background">
                <Sidebar open={sidebarOpen} onClose={() => setSidebarOpen(false)}>
                    <SidebarSection title="Dashboard">
                        <SidebarItem
                            href="/admin/dashboard"
                            icon={<HomeIcon />}
                            label="Dashboard"
                            active={isActive('/admin/dashboard')}
                        />
                    </SidebarSection>

                    <SidebarSection title="Company/Admin" className="mt-6">
                        {can('organization.view') && (
                            <SidebarItem
                                href="/admin/organizations"
                                icon={<BuildingOfficeIcon />}
                                label="Organization"
                                active={isActive('/admin/organizations')}
                            />
                        )}
                        {can('company.manage') && (
                            <SidebarItem
                                href="/admin/company/profile"
                                icon={<BuildingOfficeIcon />}
                                label="Company"
                                active={isActive('/admin/company/profile')}
                            />
                        )}
                        {canAny(['users.manage']) && (
                            <SidebarItem
                                href="/admin/users"
                                icon={<UsersIcon />}
                                label="Users"
                                active={isActive('/admin/users')}
                            />
                        )}
                        {can('roles.manage') && (
                            <SidebarItem
                                href="/admin/roles"
                                icon={<ShieldCheckIcon />}
                                label="Roles"
                                active={isActive('/admin/roles')}
                            />
                        )}
                        {can('users.manage') && (
                            <SidebarItem
                                href="/admin/permissions"
                                icon={<KeyIcon />}
                                label="Permissions"
                                active={isActive('/admin/permissions')}
                            />
                        )}
                    </SidebarSection>

                    <SidebarSection title="CRM/Operations" className="mt-6">
                        {can('customer.view') && (
                            <SidebarItem
                                href="/admin/customers"
                                icon={<BriefcaseIcon />}
                                label="Customers"
                                active={isActive('/admin/customers')}
                            />
                        )}
                        {can('location.view') && (
                            <SidebarItem
                                href="/admin/locations"
                                icon={<MapPinIcon />}
                                label="Locations"
                                active={isActive('/admin/locations')}
                            />
                        )}
                        {can('employee.view') && (
                            <SidebarItem
                                href="/admin/employees"
                                icon={<UsersIcon />}
                                label="Employees"
                                active={isActive('/admin/employees')}
                            />
                        )}
                        {can('vehicle.view') && (
                            <SidebarItem
                                href="/admin/vehicles"
                                icon={<TruckIcon />}
                                label="Vehicles"
                                active={isActive('/admin/vehicles')}
                            />
                        )}
                        {can('system.setting') && (
                            <SidebarItem
                                href="/admin/documents"
                                icon={<ClipboardDocumentIcon />}
                                label="Documents"
                                active={isActive('/admin/documents')}
                            />
                        )}
                    </SidebarSection>

                    <SidebarSection title="Service" className="mt-6">
                        {can('system.setting') && (
                            <SidebarItem
                                href="/admin/number-sequences"
                                icon={<KeyIcon />}
                                label="Number Sequences"
                                active={isActive('/admin/number-sequences')}
                            />
                        )}
                        {can('service.view') && (
                            <SidebarItem
                                href="/admin/service-packages"
                                icon={<TagIcon />}
                                label="Service"
                                active={isActive('/admin/service-packages')}
                            />
                        )}
                        {can('service.view') && (
                            <SidebarItem
                                href="/admin/bandwidth-profiles"
                                icon={<WrenchScrewdriverIcon />}
                                label="Bandwidth Profiles"
                                active={isActive('/admin/bandwidth-profiles')}
                            />
                        )}
                        {can('service.view') && (
                            <SidebarItem
                                href="/admin/speed-profiles"
                                icon={<WrenchScrewdriverIcon />}
                                label="Speed Profiles"
                                active={isActive('/admin/speed-profiles')}
                            />
                        )}
                        {can('service.view') && (
                            <SidebarItem
                                href="/admin/sla-tiers"
                                icon={<WrenchScrewdriverIcon />}
                                label="SLA Tiers"
                                active={isActive('/admin/sla-tiers')}
                            />
                        )}
                    </SidebarSection>

                    <SidebarSection title="Inventory" className="mt-6">
                        {can('inventory.view') && (
                            <SidebarItem
                                href="/admin/products"
                                icon={<TagIcon />}
                                label="Inventory"
                                active={isActive('/admin/products')}
                            />
                        )}
                        {can('inventory.view') && (
                            <SidebarItem
                                href="/admin/categories"
                                icon={<ArchiveBoxIcon />}
                                label="Categories"
                                active={isActive('/admin/categories')}
                            />
                        )}
                        {can('inventory.view') && (
                            <SidebarItem
                                href="/admin/units"
                                icon={<ArchiveBoxIcon />}
                                label="Units"
                                active={isActive('/admin/units')}
                            />
                        )}
                        {can('inventory.view') && (
                            <SidebarItem
                                href="/admin/stocks"
                                icon={<ArchiveBoxIcon />}
                                label="Stocks"
                                active={isActive('/admin/stocks')}
                            />
                        )}
                        {can('inventory.view') && (
                            <SidebarItem
                                href="/admin/inventory/find"
                                icon={<ArchiveBoxIcon />}
                                label="Item Finder"
                                active={isActive('/admin/inventory/find')}
                            />
                        )}
                        {can('inventory.view') && (
                            <SidebarItem
                                href="/admin/stock-movements"
                                icon={<ArchiveBoxIcon />}
                                label="Stock Movements"
                                active={isActive('/admin/stock-movements')}
                            />
                        )}
                    </SidebarSection>

                    <SidebarSection title="Network/Work Orders" className="mt-6">
                        {can('network_asset.view') && (
                            <SidebarItem
                                href="/admin/network-assets"
                                icon={<ServerStackIcon />}
                                label="Network Assets"
                                active={isActive('/admin/network-assets')}
                            />
                        )}
                        {can('spk.view') && (
                            <SidebarItem
                                href="/admin/spk"
                                icon={<ClipboardDocumentListIcon />}
                                label="SPK"
                                active={isActive('/admin/spk')}
                            />
                        )}
                    </SidebarSection>

                    <SidebarSection title="Finance/Reports" className="mt-6">
                        {can('billing.view') && (
                            <SidebarItem
                                href="/admin/invoices"
                                icon={<BanknotesIcon />}
                                label="Billing"
                                active={isActive('/admin/invoices')}
                            />
                        )}
                        {can('billing.view') && (
                            <SidebarItem
                                href="/admin/billing/receivables"
                                icon={<BanknotesIcon />}
                                label="Tunggakan"
                                active={isActive('/admin/billing/receivables')}
                            />
                        )}
                        {can('ticket.view') && (
                            <SidebarItem
                                href="/admin/tickets"
                                icon={<ChatBubbleLeftRightIcon />}
                                label="Ticketing"
                                active={isActive('/admin/tickets')}
                            />
                        )}
                        {canAny(['evaluation.view', 'evaluation.view.own']) && (
                            <SidebarItem
                                href="/admin/evaluations"
                                icon={<ClipboardDocumentListIcon />}
                                label="Evaluations"
                                active={isActive('/admin/evaluations')}
                            />
                        )}
                        {can('report.view') && (
                            <SidebarItem
                                href="/admin/reports"
                                icon={<ChartBarIcon />}
                                label="Reports"
                                active={isActive('/admin/reports')}
                            />
                        )}
                    </SidebarSection>

                    <SidebarSection title="System/Developer" className="mt-6">
                        <SidebarItem
                            href="/admin/components"
                            icon={<Squares2X2Icon />}
                            label="Komponen"
                            active={isActive('/admin/components')}
                        />
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
                                {company && can('company.manage') && (
                                    <Link
                                        href="/admin/company/profile"
                                        className="hidden items-center gap-2 rounded-lg px-3 py-2 text-sm font-medium text-muted-foreground hover:bg-accent hover:text-accent-foreground sm:flex"
                                    >
                                        <Cog6ToothIcon className="h-4 w-4" />
                                        <span>{company.name}</span>
                                    </Link>
                                )}
                                {user && (
                                    <Link
                                        href={route('profile.edit')}
                                        className="hidden items-center gap-2 rounded-lg px-3 py-2 text-sm font-medium text-muted-foreground hover:bg-accent hover:text-accent-foreground sm:flex"
                                        aria-current={isActive('/profile') ? 'page' : undefined}
                                    >
                                        <UserCircleIcon className="h-4 w-4" />
                                        <span>{user.name}</span>
                                    </Link>
                                )}
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    onClick={toggleDark}
                                    aria-label="Toggle dark mode"
                                >
                                    {darkMode ? (
                                        <SunIcon className="h-5 w-5" />
                                    ) : (
                                        <MoonIcon className="h-5 w-5" />
                                    )}
                                </Button>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    onClick={logout}
                                    aria-label="Logout"
                                >
                                    <ArrowRightStartOnRectangleIcon className="h-5 w-5" />
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
