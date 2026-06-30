import { cn } from '@/lib/utils';

interface EmptyStateProps {
    icon?: React.ReactNode;
    title: string;
    description?: string;
    action?: React.ReactNode;
    className?: string;
}

export function EmptyState({ icon, title, description, action, className }: EmptyStateProps) {
    return (
        <div className={cn('flex flex-col items-center justify-center py-12 text-center', className)}>
            {icon && <span className="mb-4 text-surface-400 dark:text-surface-500">{icon}</span>}
            <h3 className="font-semibold text-surface-900 dark:text-surface-100">{title}</h3>
            {description && <p className="mt-1 text-sm text-surface-500 dark:text-surface-400">{description}</p>}
            {action && <div className="mt-4">{action}</div>}
        </div>
    );
}
