import { useState, useEffect } from 'react';
import { Head, router, usePage } from '@inertiajs/react';
import type { PageProps } from '@/types';
import {
    ArrowRightStartOnRectangleIcon,
    Bars3Icon,
    ChevronDownIcon,
    MoonIcon,
    SunIcon,
    UserCircleIcon,
} from '@heroicons/react/24/outline';
import {
    Button,
    Dropdown,
    DropdownItem,
    DropdownSeparator,
    Sidebar,
    SidebarItem,
    SidebarSection,
    Topbar,
} from '@/Components/ui';
import { NotificationBell } from '@/Components/composite/NotificationBell';
import { CompanySwitcher } from '@/Components/composite/CompanySwitcher';
import { useCompany } from '@/hooks/useCompany';
import { usePermission } from '@/hooks/usePermission';
import { useNavigationBusy } from '@/hooks/useNavigationBusy';
import { useToast } from '@/hooks/useToast';
import { activeNavHref, visibleNavGroups } from '@/lib/navigation';

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
    const { permissions } = usePermission();
    useToast();
    const navigating = useNavigationBusy();

    useEffect(() => {
        document.documentElement.classList.toggle('dark', darkMode);
    }, [darkMode]);

    const toggleDark = () => {
        const enabled = !darkMode;
        setDarkMode(enabled);
        localStorage.setItem('darkMode', String(enabled));
    };

    const logout = () => router.post(route('logout'));

    const groups = visibleNavGroups({
        permissions,
        canMyWork: props.canMyWork === true,
        canConsolidate: props.companyHierarchy?.can_consolidate === true,
        developerGalleryEnabled: props.developerGalleryEnabled === true,
        isActiveUser: user?.is_active !== false,
        workQueues: props.workQueues ?? {},
    });
    const activeHref = activeNavHref(groups, url);

    return (
        <>
            <Head title={title} />
            <div className="flex h-screen overflow-hidden bg-muted/30 text-foreground dark:bg-background">
                <Sidebar
                    open={sidebarOpen}
                    onClose={() => setSidebarOpen(false)}
                    brandName={company?.name ?? 'inbils'}
                    brandLogo={company?.logo}
                >
                    <div className="flex flex-col gap-6">
                        <CompanySwitcher onSwitched={() => setSidebarOpen(false)} />
                        {groups.map((group) => (
                            <SidebarSection
                                key={group.id}
                                title={group.title}
                                defaultOpen={group.items.some((item) => item.href === activeHref)}
                            >
                                {group.items.map((item) => (
                                    <SidebarItem
                                        key={item.href}
                                        href={item.href}
                                        icon={<item.icon />}
                                        label={item.label}
                                        active={item.href === activeHref}
                                        badge={item.badge}
                                    />
                                ))}
                            </SidebarSection>
                        ))}
                    </div>
                </Sidebar>

                <div className="flex min-w-0 flex-1 flex-col overflow-hidden">
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
                                {user && <NotificationBell />}
                                {user && (
                                    <Dropdown
                                        trigger={
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="sm"
                                                className="gap-2"
                                                aria-label="Open user menu"
                                            >
                                                <UserCircleIcon className="h-5 w-5" />
                                                <span className="hidden max-w-36 truncate sm:inline">
                                                    {user.name}
                                                </span>
                                                <ChevronDownIcon className="h-4 w-4 text-muted-foreground" />
                                            </Button>
                                        }
                                    >
                                        <div className="px-3 py-2">
                                            <p className="truncate text-sm font-semibold">
                                                {user.name}
                                            </p>
                                            <p className="truncate text-xs text-muted-foreground">
                                                {user.email}
                                            </p>
                                        </div>
                                        <DropdownSeparator />
                                        <DropdownItem
                                            onClick={() => router.get(route('profile.edit'))}
                                        >
                                            <UserCircleIcon className="h-4 w-4" />
                                            Profile
                                        </DropdownItem>
                                        <DropdownItem onClick={toggleDark}>
                                            {darkMode ? (
                                                <SunIcon className="h-4 w-4" />
                                            ) : (
                                                <MoonIcon className="h-4 w-4" />
                                            )}
                                            {darkMode ? 'Light mode' : 'Dark mode'}
                                        </DropdownItem>
                                        <DropdownSeparator />
                                        <DropdownItem onClick={logout} destructive>
                                            <ArrowRightStartOnRectangleIcon className="h-4 w-4" />
                                            Logout
                                        </DropdownItem>
                                    </Dropdown>
                                )}
                            </>
                        }
                    />
                    <div role="status" aria-live="polite" className="sr-only">
                        {navigating ? 'Memuat halaman…' : ''}
                    </div>
                    <main
                        aria-busy={navigating || undefined}
                        className="min-h-0 flex-1 overflow-y-auto p-4 [scrollbar-color:hsl(var(--muted-foreground))_transparent] [scrollbar-width:thin] md:p-6 [&::-webkit-scrollbar-thumb]:rounded-full [&::-webkit-scrollbar-thumb]:bg-muted-foreground/40 [&::-webkit-scrollbar-track]:bg-transparent [&::-webkit-scrollbar]:w-1.5"
                    >
                        <div className="mx-auto w-full max-w-[1600px] space-y-6">{children}</div>
                    </main>
                </div>
            </div>
        </>
    );
}
