import { Link } from '@inertiajs/react';
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
}

export function SidebarSection({ title, children, className }: SidebarSectionProps) {
    return (
        <div className={cn(className)}>
            {title && (
                <p className="mb-2 px-3 text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                    {title}
                </p>
            )}
            <nav className="space-y-1">{children}</nav>
        </div>
    );
}

interface SidebarProps {
    children: React.ReactNode;
    open?: boolean;
    onClose?: () => void;
    className?: string;
}

export function Sidebar({ children, open, onClose, className }: SidebarProps) {
    return (
        <>
            {open !== undefined && open && (
                <div className="fixed inset-0 z-40 bg-black/50 lg:hidden" onClick={onClose} />
            )}
            <aside
                className={cn(
                    'fixed inset-y-0 left-0 z-50 flex w-72 transform flex-col border-r border-border bg-card p-4 text-card-foreground shadow-xl transition-transform dark:bg-card lg:static lg:translate-x-0 lg:shadow-none',
                    open === undefined
                        ? 'hidden lg:block'
                        : open
                          ? 'translate-x-0'
                          : '-translate-x-full lg:translate-x-0',
                    className,
                )}
            >
                <div className="mb-6 flex items-center gap-3 px-2">
                    <div className="flex h-9 w-9 items-center justify-center rounded-lg bg-primary text-sm font-semibold text-primary-foreground">
                        in
                    </div>
                    <div>
                        <p className="text-sm font-semibold leading-none">inbils</p>
                        <p className="text-xs text-muted-foreground">ISP ERP</p>
                    </div>
                </div>
                {children}
            </aside>
        </>
    );
}
