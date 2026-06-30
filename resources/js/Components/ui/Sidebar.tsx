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
                    ? 'bg-brand-50 text-brand-700 dark:bg-brand-900/30 dark:text-brand-300 border-l-2 border-brand-600'
                    : 'text-surface-600 hover:bg-surface-100 dark:text-surface-400 dark:hover:bg-surface-800',
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
                <p className="mb-2 px-3 text-xs font-semibold uppercase tracking-wider text-surface-400 dark:text-surface-500">
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
                    'fixed inset-y-0 left-0 z-50 w-64 transform border-r border-surface-200 bg-white p-4 transition-transform dark:border-surface-800 dark:bg-surface-900 lg:static lg:translate-x-0',
                    open === undefined ? 'hidden lg:block' : open ? 'translate-x-0' : '-translate-x-full lg:translate-x-0',
                    className,
                )}
            >
                <div className="mb-6 flex items-center gap-2 px-3">
                    <span className="text-lg font-bold text-surface-900 dark:text-surface-100">inbils</span>
                </div>
                {children}
            </aside>
        </>
    );
}
