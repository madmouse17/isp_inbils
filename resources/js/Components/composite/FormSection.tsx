import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

export interface FormSectionProps {
    title?: string;
    description?: string;
    children?: ReactNode;
    className?: string;
}

export function FormSection({ title, description, children, className }: FormSectionProps) {
    return (
        <div className={cn('rounded-lg border border-border bg-card p-6', className)}>
            {title && <h3 className="mb-2 text-lg font-semibold">{title}</h3>}
            {description && <p className="mb-4 text-sm text-muted-foreground">{description}</p>}
            <div className="space-y-4">{children}</div>
        </div>
    );
}
