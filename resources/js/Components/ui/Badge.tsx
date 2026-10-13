import * as React from 'react';
import { cn } from '@/lib/utils';

/*
 * The tinted tones pair a tint with a text colour that reaches 4.5:1 on white, on the muted row and on
 * both dark surfaces (antislop contrast tool). `destructive` is the only solid fill: keep it for a breach,
 * so red keeps its weight.
 */
const variants = {
    default: 'border-transparent bg-primary text-primary-foreground',
    secondary: 'border-transparent bg-secondary text-secondary-foreground',
    destructive:
        'border-transparent bg-red-600 text-white dark:bg-destructive dark:text-destructive-foreground',
    outline: 'text-foreground',
    success: 'border-transparent bg-emerald-500/15 text-emerald-800 dark:text-emerald-400',
    warning: 'border-transparent bg-amber-500/15 text-amber-800 dark:text-amber-400',
    danger: 'border-transparent bg-red-500/10 text-red-700 dark:text-red-400',
    info: 'border-transparent bg-sky-500/15 text-sky-700 dark:text-sky-400',
    neutral: 'border-transparent bg-slate-500/15 text-slate-700 dark:text-slate-300',
    muted: 'border-border bg-transparent text-slate-600 dark:text-slate-400',
    brand: 'border-transparent bg-primary/10 text-brand-700 dark:bg-primary/20 dark:text-brand-300',
} as const;

export type BadgeVariant = keyof typeof variants;

export interface BadgeProps extends React.HTMLAttributes<HTMLDivElement> {
    variant?: BadgeVariant;
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
