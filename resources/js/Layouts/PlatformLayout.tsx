import { useRef, useState } from 'react';
import { Head, router, usePage } from '@inertiajs/react';
import {
    Building2,
    ChevronDown,
    CircleUser,
    FileArchive,
    KeyRound,
    LayoutDashboard,
    LogOut,
    Menu,
    Moon,
    ScrollText,
    ShieldCheck,
    Sun,
} from 'lucide-react';
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
import { OperatorBand } from '@/Components/composite/platform/OperatorBand';
import { useNavigationBusy } from '@/hooks/useNavigationBusy';
import { useTheme } from '@/hooks/useTheme';
import { useToast } from '@/hooks/useToast';
import type { PlatformSharedProps } from '@/types/platform';

interface PlatformLayoutProps {
    title: string;
    children: React.ReactNode;
}

export default function PlatformLayout({ title, children }: PlatformLayoutProps) {
    const [sidebarOpen, setSidebarOpen] = useState(false);
    const toggleRef = useRef<HTMLButtonElement>(null);
    const { dark, toggle: toggleDark } = useTheme();
    const { url, props } = usePage();
    const admin = (props as unknown as PlatformSharedProps).platform.admin;
    const navigating = useNavigationBusy();
    useToast();

    const path = url.split('?')[0].replace(/\/$/, '') || '/';
    const dashboard = route('platform.dashboard', undefined, false);
    const companyCreate = route('platform.companies.create', undefined, false);
    const roleTemplates = route('platform.role-templates.index', undefined, false);
    const exportsIndex = route('platform.exports.index', undefined, false);
    const audit = route('platform.audit.index', undefined, false);
    const companyPrefix = `${companyCreate.slice(0, -'/create'.length)}/`;

    const nav = [
        {
            label: 'Dasbor',
            href: dashboard,
            icon: LayoutDashboard,
            active:
                path === dashboard || (path.startsWith(companyPrefix) && path !== companyCreate),
        },
        {
            label: 'Perusahaan baru',
            href: companyCreate,
            icon: Building2,
            active: path === companyCreate,
        },
        {
            label: 'Templat peran',
            href: roleTemplates,
            icon: KeyRound,
            active: path === roleTemplates || path.startsWith(`${roleTemplates}/`),
        },
        {
            label: 'Ekspor',
            href: exportsIndex,
            icon: FileArchive,
            active: path === exportsIndex,
        },
        {
            label: 'Audit platform',
            href: audit,
            icon: ScrollText,
            active: path === audit,
        },
    ];

    return (
        <>
            <Head title={title} />
            <a
                href="#main-content"
                className="sr-only z-[60] rounded-md bg-background px-3 py-2 text-sm font-medium focus:not-sr-only focus:fixed focus:top-2 focus:left-2 focus:ring-2 focus:ring-ring"
            >
                Lewati ke konten utama
            </a>
            <div className="flex h-dvh h-screen flex-col overflow-hidden bg-muted/30 text-foreground dark:bg-background">
                <OperatorBand />
                <div className="flex min-h-0 flex-1 overflow-hidden">
                    <Sidebar
                        open={sidebarOpen}
                        onClose={() => setSidebarOpen(false)}
                        returnFocusRef={toggleRef}
                        brandName="Inbils Platform"
                        className="lg:h-full"
                    >
                        <nav aria-label="Navigasi panel operator">
                            <SidebarSection>
                                {nav.map((item) => (
                                    <SidebarItem
                                        key={item.href}
                                        href={item.href}
                                        icon={<item.icon />}
                                        label={item.label}
                                        active={item.active}
                                    />
                                ))}
                            </SidebarSection>
                        </nav>
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
                                    <Menu className="size-5" aria-hidden="true" />
                                </Button>
                            }
                            right={
                                admin ? (
                                    <Dropdown
                                        trigger={
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="sm"
                                                className="gap-2"
                                                aria-label="Menu operator"
                                            >
                                                <CircleUser className="size-5" aria-hidden="true" />
                                                <span className="hidden max-w-36 truncate sm:inline">
                                                    {admin.name}
                                                </span>
                                                <ChevronDown
                                                    className="size-4 text-muted-foreground"
                                                    aria-hidden="true"
                                                />
                                            </Button>
                                        }
                                    >
                                        <div className="px-3 py-2">
                                            <p className="truncate text-sm font-semibold">
                                                {admin.name}
                                            </p>
                                            <p className="truncate text-xs text-muted-foreground">
                                                {admin.email}
                                            </p>
                                        </div>
                                        <DropdownSeparator />
                                        <DropdownItem
                                            onClick={() =>
                                                router.get(route('platform.two-factor.setup'))
                                            }
                                        >
                                            <ShieldCheck className="size-4" aria-hidden="true" />
                                            Autentikasi dua langkah
                                        </DropdownItem>
                                        <DropdownItem onClick={toggleDark}>
                                            {dark ? (
                                                <Sun className="size-4" aria-hidden="true" />
                                            ) : (
                                                <Moon className="size-4" aria-hidden="true" />
                                            )}
                                            {dark ? 'Mode terang' : 'Mode gelap'}
                                        </DropdownItem>
                                        <DropdownSeparator />
                                        <DropdownItem
                                            onClick={() => router.post(route('platform.logout'))}
                                            destructive
                                        >
                                            <LogOut className="size-4" aria-hidden="true" />
                                            Keluar
                                        </DropdownItem>
                                    </Dropdown>
                                ) : null
                            }
                        />
                        <div role="status" aria-live="polite" className="sr-only">
                            {navigating ? 'Memuat halaman…' : ''}
                        </div>
                        <main
                            id="main-content"
                            aria-busy={navigating || undefined}
                            className="min-h-0 flex-1 overflow-y-auto p-4 md:p-6"
                        >
                            <div className="mx-auto space-y-6 w-full max-w-[1600px]">
                                {children}
                            </div>
                        </main>
                    </div>
                </div>
            </div>
        </>
    );
}
