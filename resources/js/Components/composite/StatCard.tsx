import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

export interface StatCardProps {
    title: string;
    value: string | number;
    subtitle?: string;
    icon?: ReactNode;
    className?: string;
}

export function StatCard({ title, value, subtitle, icon, className }: StatCardProps) {
    return (
        <div className={cn('rounded-lg border border-border bg-card p-4', className)}>
            {icon && <div className="mb-2 text-foreground">{icon}</div>}
            <div className="text-2xl font-bold">{value}</div>
            {title && <div className="text-sm text-muted-foreground">{title}</div>}
            {subtitle && <div className="text-xs text-muted-foreground">{subtitle}</div>}
        </div>
    );
}
