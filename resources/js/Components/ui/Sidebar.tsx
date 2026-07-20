import { ChevronDownIcon } from '@heroicons/react/20/solid';
import { Link } from '@inertiajs/react';
import { useState } from 'react';
import { cn } from '@/lib/utils';

interface SidebarItemProps {
    href: string;
    icon?: React.ReactNode;
    label: string;
    active?: boolean;
    badge?: string | number;
}

export function SidebarItem({ href, icon, label, active, badge }: SidebarItemProps) {
    return (
        <Link
            href={href}
            className={cn(
                'flex items-center gap-3 rounded-md px-3 py-2 text-sm font-medium transition-colors focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring',
                active
                    ? 'bg-primary text-primary-foreground shadow-sm'
                    : 'text-muted-foreground hover:bg-accent hover:text-accent-foreground',
            )}
            aria-current={active ? 'page' : undefined}
        >
            {icon && <span className="h-5 w-5 shrink-0">{icon}</span>}
            <span className="flex-1 truncate">{label}</span>
            {badge !== undefined && (
                <span className="rounded-full bg-primary/10 px-2 py-0.5 text-xs font-medium text-primary">
                    {badge}
                </span>
            )}
        </Link>
    );
}

interface SidebarSectionProps {
    title?: string;
    children: React.ReactNode;
    className?: string;
    defaultOpen?: boolean;
}

export function SidebarSection({ title, children, className, defaultOpen = true }: SidebarSectionProps) {
    const [expanded, setExpanded] = useState(defaultOpen);

    if (!title) {
        return (
            <div className={cn(className)}>
                <nav className="space-y-1">{children}</nav>
            </div>
        );
    }

    return (
        <details
            className={cn('group', className)}
            open={expanded}
            onToggle={(event) => setExpanded(event.currentTarget.open)}
        >
            <summary className="mb-2 flex cursor-pointer list-none items-center justify-between gap-2 rounded-md px-3 py-1 text-xs font-semibold uppercase tracking-wider text-muted-foreground transition-colors hover:bg-accent hover:text-accent-foreground focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring [&::-webkit-details-marker]:hidden">
                <span className="truncate">
                    {title}
                </span>
                <ChevronDownIcon className="h-4 w-4 shrink-0 transition-transform group-open:rotate-180" />
            </summary>
            <nav className="space-y-1">{children}</nav>
        </details>
    );
}

interface SidebarProps {
    children: React.ReactNode;
    open?: boolean;
    onClose?: () => void;
    className?: string;
    brandName?: string;
    brandLogo?: string | null;
}

export function Sidebar({ children, open, onClose, className, brandName = 'inbils', brandLogo }: SidebarProps) {
    const initials = brandName
        .split(/\s+/)
        .map((word) => word[0])
        .join('')
        .slice(0, 2)
        .toLowerCase();

    return (
        <>
            {open !== undefined && open && (
                <div className="fixed inset-0 z-40 bg-black/50 lg:hidden" onClick={onClose} />
            )}
            <aside
                className={cn(
                    'fixed inset-y-0 left-0 z-50 flex h-screen w-72 transform flex-col border-r border-border bg-card text-card-foreground shadow-xl transition-transform dark:bg-card lg:static lg:translate-x-0 lg:shadow-none',
                    open === undefined
                        ? 'hidden lg:block'
                        : open
                          ? 'translate-x-0'
                          : '-translate-x-full lg:translate-x-0',
                    className,
                )}
            >
                <div className="flex shrink-0 items-center gap-3 px-6 py-4">
                    <div
                        className={cn(
                            'flex h-9 w-9 items-center justify-center rounded-lg text-sm font-semibold',
                            brandLogo
                                ? 'overflow-hidden border border-border bg-background p-1'
                                : 'bg-primary text-primary-foreground',
                        )}
                    >
                        {brandLogo ? (
                            <img
                                src={brandLogo}
                                alt={`${brandName} logo`}
                                className="h-full w-full rounded-lg object-contain"
                            />
                        ) : (
                            initials
                        )}
                    </div>
                    <div className="min-w-0">
                        <p className="truncate text-sm font-semibold leading-none">{brandName}</p>
                        <p className="text-xs text-muted-foreground">ISP ERP</p>
                    </div>
                </div>
                <div className="min-h-0 flex-1 overflow-y-auto px-4 pb-4 [scrollbar-color:hsl(var(--muted-foreground))_transparent] [scrollbar-width:thin] [&::-webkit-scrollbar]:w-1.5 [&::-webkit-scrollbar-thumb]:rounded-full [&::-webkit-scrollbar-thumb]:bg-muted-foreground/40 [&::-webkit-scrollbar-track]:bg-transparent">
                    {children}
                </div>
            </aside>
        </>
    );
}
