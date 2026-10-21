import { useState, useRef } from 'react';
import { Head, router, usePage } from '@inertiajs/react';
import type { PageProps } from '@/types';
import { Building2, ChevronDown, CircleUser, LogOut, Menu, Moon, Sun } from 'lucide-react';
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
import { TwoFactorPrompt } from '@/Components/composite/TwoFactorPrompt';
import { CompanySwitcher } from '@/Components/composite/CompanySwitcher';
import { ImpersonationBanner } from '@/Components/composite/ImpersonationBanner';
import { useCompany } from '@/hooks/useCompany';
import { usePermission } from '@/hooks/usePermission';
import { useNavigationBusy } from '@/hooks/useNavigationBusy';
import { useTheme } from '@/hooks/useTheme';
import { useToast } from '@/hooks/useToast';
import { activeNavHref, visibleNavGroups } from '@/lib/navigation';
import { useToastInset } from '@/Components/ui/Toast';

interface AdminLayoutProps {
    /** Names the page in the tab and the top bar; every page passes one. */
    title: string;
    children: React.ReactNode;
}

/** Names the subsidiary the user acts in and offers the way back to its Head, which a switcher alone leaves unsaid. */
function ParentRibbon() {
    const { company, companyHierarchy } = usePage<PageProps>().props;
    const parent = companyHierarchy?.parent;

    return parent && company ? <RibbonBar companyName={company.name} parent={parent} /> : null;
}

function RibbonBar({
    companyName,
    parent,
}: {
    companyName: string;
    parent: { id: string; name: string; can_switch: boolean };
}) {
    const bar = useRef<HTMLDivElement>(null);
    const [busy, setBusy] = useState(false);
    useToastInset('--parent-ribbon-height', bar);

    return (
        <div
            ref={bar}
            role="region"
            aria-label="Perusahaan induk"
            className="flex shrink-0 flex-wrap items-center gap-x-4 gap-y-2 border-b border-border bg-secondary px-4 py-2 text-sm text-secondary-foreground md:px-6"
        >
            <p className="flex min-w-0 items-center gap-2">
                <Building2 className="h-4 w-4 shrink-0" aria-hidden="true" />
                <span className="min-w-0">
                    Sedang di anak perusahaan <strong>{companyName}</strong>
                </span>
            </p>
            {parent.can_switch ? (
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    loading={busy}
                    aria-label={`Kembali ke induk: ${parent.name}`}
                    onClick={() =>
                        router.post(
                            route('admin.company.switch'),
                            { company_id: parent.id },
                            { onStart: () => setBusy(true), onFinish: () => setBusy(false) },
                        )
                    }
                >
                    Kembali ke induk
                </Button>
            ) : null}
        </div>
    );
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
            <a
                href="#main-content"
                className="sr-only z-60 rounded-md bg-background px-3 py-2 text-sm font-medium focus:not-sr-only focus:fixed focus:top-2 focus:left-2 focus:ring-2 focus:ring-ring"
            >
                Langsung ke konten
            </a>
            <Head title={title} />
            <div className="flex h-screen flex-col overflow-hidden bg-muted/30 text-foreground dark:bg-background">
                <ImpersonationBanner />
                <ParentRibbon />
                <div className="flex min-h-0 flex-1 overflow-hidden">
                    <Sidebar
                        open={sidebarOpen}
                        onClose={() => setSidebarOpen(false)}
                        returnFocusRef={toggleRef}
                        className="lg:h-full"
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
                                                <CircleUser
                                                    className="h-4 w-4"
                                                    aria-hidden="true"
                                                />
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
                            id="main-content"
                            // Focusable by script, so the skip link lands the next Tab in the page and not back in the menu.
                            tabIndex={-1}
                            aria-busy={navigating || undefined}
                            // scroll-pb: a sticky action bar at the foot of a page never hides the field that has focus.
                            className="min-h-0 flex-1 scroll-pb-24 scrollbar-thin [scrollbar-color:hsl(var(--muted-foreground))_transparent] overflow-y-auto p-4 focus:outline-hidden md:p-6 [&::-webkit-scrollbar]:w-1.5 [&::-webkit-scrollbar-thumb]:rounded-full [&::-webkit-scrollbar-thumb]:bg-muted-foreground/40 [&::-webkit-scrollbar-track]:bg-transparent"
                        >
                            <div className="mx-auto space-y-6 w-full max-w-[1600px]">
                                <TwoFactorPrompt />
                                {children}
                            </div>
                        </main>
                    </div>
                </div>
            </div>
        </>
    );
}
