import { useState, useRef } from 'react';
import { Head, router, usePage } from '@inertiajs/react';
import type { PageProps } from '@/types';
import { ChevronDown, CircleUser, LogOut, Menu, Moon, Sun } from 'lucide-react';
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
import { useTheme } from '@/hooks/useTheme';
import { useToast } from '@/hooks/useToast';
import { activeNavHref, visibleNavGroups } from '@/lib/navigation';

interface AdminLayoutProps {
    /** Names the page in the tab and the top bar; every page passes one. */
    title: string;
    children: React.ReactNode;
}

export default function AdminLayout({ title, children }: AdminLayoutProps) {
    const [sidebarOpen, setSidebarOpen] = useState(false);
    const toggleRef = useRef<HTMLButtonElement>(null);
    const { dark, toggle: toggleDark } = useTheme();
    const { url, props } = usePage<PageProps>();
    const user = props.auth.user;
    const company = useCompany();
    const { permissions } = usePermission();
    useToast();
    const navigating = useNavigationBusy();

    const logout = () => router.post(route('logout'));

    const groups = visibleNavGroups({
        permissions,
        canMyWork: props.canMyWork === true,
        canTechnicianReport: props.canTechnicianReport === true,
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
                    returnFocusRef={toggleRef}
                    brandName={company?.name ?? 'Inbils'}
                    brandLogo={company?.logo}
                >
                    <div className="flex flex-col gap-6">
                        <CompanySwitcher onSwitched={() => setSidebarOpen(false)} />
                        <nav aria-label="Navigasi utama" className="flex flex-col gap-6">
                            {groups.map((group) => (
                                <SidebarSection
                                    key={group.id}
                                    title={group.title}
                                    defaultOpen={group.items.some(
                                        (item) => item.href === activeHref,
                                    )}
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
                        </nav>
                    </div>
                </Sidebar>

                <div className="flex min-w-0 flex-1 flex-col overflow-hidden">
                    <Topbar
                        title={title}
                        left={
                            <Button
                                ref={toggleRef}
                                type="button"
                                variant="ghost"
                                size="sm"
                                onClick={() => setSidebarOpen(!sidebarOpen)}
                                className="lg:hidden"
                                aria-label="Menu navigasi"
                                aria-expanded={sidebarOpen}
                            >
                                <Menu className="h-5 w-5" aria-hidden="true" />
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
                                                aria-label="Menu pengguna"
                                            >
                                                <CircleUser
                                                    className="h-5 w-5"
                                                    aria-hidden="true"
                                                />
                                                <span className="hidden max-w-36 truncate sm:inline">
                                                    {user.name}
                                                </span>
                                                <ChevronDown
                                                    className="h-4 w-4 text-muted-foreground"
                                                    aria-hidden="true"
                                                />
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
                                            <CircleUser className="h-4 w-4" aria-hidden="true" />
                                            Profil
                                        </DropdownItem>
                                        <DropdownItem onClick={toggleDark}>
                                            {dark ? (
                                                <Sun className="h-4 w-4" aria-hidden="true" />
                                            ) : (
                                                <Moon className="h-4 w-4" aria-hidden="true" />
                                            )}
                                            {dark ? 'Mode terang' : 'Mode gelap'}
                                        </DropdownItem>
                                        <DropdownSeparator />
                                        <DropdownItem onClick={logout} destructive>
                                            <LogOut className="h-4 w-4" aria-hidden="true" />
                                            Keluar
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
                        className="min-h-0 flex-1 scrollbar-thin [scrollbar-color:hsl(var(--muted-foreground))_transparent] overflow-y-auto p-4 md:p-6 [&::-webkit-scrollbar]:w-1.5 [&::-webkit-scrollbar-thumb]:rounded-full [&::-webkit-scrollbar-thumb]:bg-muted-foreground/40 [&::-webkit-scrollbar-track]:bg-transparent"
                    >
                        <div className="mx-auto space-y-6 w-full max-w-[1600px]">{children}</div>
                    </main>
                </div>
            </div>
        </>
    );
}
