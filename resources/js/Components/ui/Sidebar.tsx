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
                'flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition-colors',
                active
                    ? 'border-l-2 border-primary bg-primary/10 text-primary'
                    : 'text-muted-foreground hover:bg-accent hover:text-accent-foreground',
            )}
            aria-current={active ? 'page' : undefined}
        >
            {icon && <span className="h-5 w-5 shrink-0">{icon}</span>}
            <span className="flex-1 truncate">{label}</span>
            {badge !== undefined && (
                <span className="rounded-full bg-brand-100 px-2 py-0.5 text-xs font-medium text-brand-700 dark:bg-brand-900/40 dark:text-brand-300">
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
                    'fixed inset-y-0 left-0 z-50 w-64 transform border-r border-border bg-card p-4 text-card-foreground transition-transform lg:static lg:translate-x-0',
                    open === undefined ? 'hidden lg:block' : open ? 'translate-x-0' : '-translate-x-full lg:translate-x-0',
                    className,
                )}
            >
                <div className="mb-6 flex items-center gap-2 px-3">
                    <span className="text-lg font-bold text-foreground">inbils</span>
                </div>
                {children}
            </aside>
        </>
    );
}
