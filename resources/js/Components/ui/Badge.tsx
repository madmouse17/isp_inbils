import * as React from 'react';
import { cn } from '@/lib/utils';

const variants: Record<string, string> = {
    default: 'border-transparent bg-primary text-primary-foreground shadow',
    secondary: 'border-transparent bg-secondary text-secondary-foreground',
    destructive: 'border-transparent bg-destructive text-destructive-foreground shadow',
    danger: 'border-transparent bg-destructive text-destructive-foreground shadow',
    outline: 'text-foreground',
    success: 'border-transparent bg-emerald-500/15 text-emerald-700 dark:text-emerald-400',
    warning: 'border-transparent bg-amber-500/15 text-amber-700 dark:text-amber-400',
    info: 'border-transparent bg-sky-500/15 text-sky-700 dark:text-sky-400',
};

export interface BadgeProps extends React.HTMLAttributes<HTMLDivElement> {
    variant?: keyof typeof variants;
    dot?: boolean;
}

function Badge({ className, variant = 'default', dot = false, children, ...props }: BadgeProps) {
    return (
        <div
            className={cn(
                'inline-flex items-center gap-1 rounded-md border px-2.5 py-0.5 text-xs font-semibold transition-colors',
                variants[variant],
                className,
            )}
            {...props}
        >
            {dot ? (
                <span className="h-1.5 w-1.5 rounded-full bg-current" aria-hidden="true" />
            ) : null}
            {children}
        </div>
    );
}

const badgeVariants = variants;

export { Badge, badgeVariants };
