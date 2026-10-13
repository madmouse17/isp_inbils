import { ChevronDownIcon } from '@heroicons/react/20/solid';
import { Link } from '@inertiajs/react';
import { Children, useEffect, useRef, useState, useSyncExternalStore } from 'react';
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
                'flex items-center gap-3 rounded-md px-3 py-2 text-sm font-medium transition-colors focus-visible:ring-1 focus-visible:ring-ring focus-visible:outline-hidden',
                active
                    ? 'bg-primary text-primary-foreground shadow-xs'
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

export function SidebarSection({
    title,
    children,
    className,
    defaultOpen = true,
}: SidebarSectionProps) {
    const [expanded, setExpanded] = useState(defaultOpen);

    // A section whose items were all gated off must not leave an empty header behind.
    if (Children.toArray(children).length === 0) {
        return null;
    }

    if (!title) {
        return (
            <div className={cn(className)}>
                <div className="space-y-1">{children}</div>
            </div>
        );
    }

    return (
        <details
            className={cn('group', className)}
            open={expanded}
            onToggle={(event) => setExpanded(event.currentTarget.open)}
        >
            <summary className="mb-2 flex cursor-pointer list-none items-center justify-between gap-2 rounded-md px-3 py-1 text-xs font-semibold tracking-wider text-muted-foreground uppercase transition-colors hover:bg-accent hover:text-accent-foreground focus-visible:ring-1 focus-visible:ring-ring focus-visible:outline-hidden [&::-webkit-details-marker]:hidden">
                <span className="truncate">{title}</span>
                <ChevronDownIcon className="h-4 w-4 shrink-0 transition-transform group-open:rotate-180" />
            </summary>
            <div className="space-y-1">{children}</div>
        </details>
    );
}

/** Tailwind `lg`: from here the sidebar is a static column, below it a drawer. */
const DESKTOP_QUERY = '(min-width: 1024px)';

function subscribeDesktop(onChange: () => void) {
    const query = window.matchMedia(DESKTOP_QUERY);
    query.addEventListener('change', onChange);

    return () => query.removeEventListener('change', onChange);
}

const FOCUSABLE =
    'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), summary, [tabindex]:not([tabindex="-1"])';

interface SidebarProps {
    children: React.ReactNode;
    open?: boolean;
    onClose?: () => void;
    /** Element that regains focus when the drawer closes (the button that opened it). */
    returnFocusRef?: React.RefObject<HTMLElement>;
    className?: string;
    brandName?: string;
    brandLogo?: string | null;
}

export function Sidebar({
    children,
    open,
    onClose,
    returnFocusRef,
    className,
    brandName = 'inbils',
    brandLogo,
}: SidebarProps) {
    const asideRef = useRef<HTMLElement>(null);
    const isDesktop = useSyncExternalStore(
        subscribeDesktop,
        () => window.matchMedia(DESKTOP_QUERY).matches,
        () => true,
    );
    const drawerOpen = open === true && !isDesktop;
    const drawerClosed = open === false && !isDesktop;
    const initials = brandName
        .split(/\s+/)
        .map((word) => word[0])
        .join('')
        .slice(0, 2)
        .toLowerCase();

    useEffect(() => {
        if (!drawerOpen) {
            return;
        }

        asideRef.current?.querySelector<HTMLElement>(FOCUSABLE)?.focus();
        const opener = returnFocusRef?.current;

        return () => opener?.focus();
    }, [drawerOpen, returnFocusRef]);

    const handleKeyDown = (event: React.KeyboardEvent<HTMLElement>) => {
        const aside = asideRef.current;

        // Keys from portaled menus bubble through React but are not ours to handle.
        if (
            !drawerOpen ||
            !aside ||
            event.defaultPrevented ||
            !aside.contains(event.target as Node)
        ) {
            return;
        }

        if (event.key === 'Escape') {
            onClose?.();

            return;
        }

        if (event.key !== 'Tab') {
            return;
        }

        const focusable = [...aside.querySelectorAll<HTMLElement>(FOCUSABLE)].filter(
            (element) => element.offsetParent !== null,
        );
        const first = focusable[0];
        const last = focusable[focusable.length - 1];

        if (event.shiftKey && document.activeElement === first) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first.focus();
        }
    };

    return (
        <>
            {open !== undefined && open && (
                <div className="fixed inset-0 z-40 bg-black/50 lg:hidden" onClick={onClose} />
            )}
            <aside
                ref={asideRef}
                onKeyDown={handleKeyDown}
                // A closed drawer is only translated off-screen: inert keeps its links out of the tab order.
                {...(drawerClosed ? { inert: '' } : {})}
                className={cn(
                    'fixed inset-y-0 left-0 z-50 flex h-screen w-72 transform flex-col border-r border-border bg-card text-card-foreground shadow-xl transition-transform lg:static lg:translate-x-0 lg:shadow-none dark:bg-card',
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
                        <p className="truncate text-sm leading-none font-semibold">{brandName}</p>
                        <p className="text-xs text-muted-foreground">ISP ERP</p>
                    </div>
                </div>
                <div className="min-h-0 flex-1 scrollbar-thin [scrollbar-color:hsl(var(--muted-foreground))_transparent] overflow-y-auto px-4 pb-4 [&::-webkit-scrollbar]:w-1.5 [&::-webkit-scrollbar-thumb]:rounded-full [&::-webkit-scrollbar-thumb]:bg-muted-foreground/40 [&::-webkit-scrollbar-track]:bg-transparent">
                    {children}
                </div>
            </aside>
        </>
    );
}
