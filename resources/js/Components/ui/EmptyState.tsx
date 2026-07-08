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
            {icon && <span className="mb-4 text-muted-foreground">{icon}</span>}
            <h3 className="font-semibold text-foreground">{title}</h3>
            {description && <p className="mt-1 text-sm text-muted-foreground">{description}</p>}
            {action && <div className="mt-4">{action}</div>}
        </div>
    );
}
