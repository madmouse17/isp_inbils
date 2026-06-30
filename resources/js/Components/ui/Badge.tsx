import { cn } from '@/lib/utils';

const variants = {
    neutral: 'bg-surface-100 text-surface-600 dark:bg-surface-800 dark:text-surface-300',
    brand: 'bg-brand-50 text-brand-700 dark:bg-brand-900/30 dark:text-brand-300',
    success: 'bg-green-50 text-success dark:bg-green-900/20 dark:text-green-400',
    warning: 'bg-amber-50 text-warning dark:bg-amber-900/20 dark:text-amber-400',
    danger: 'bg-red-50 text-danger dark:bg-red-900/20 dark:text-red-400',
} as const;

const sizes = {
    sm: 'px-2 py-0.5 text-xs',
    md: 'px-2.5 py-0.5 text-xs',
} as const;

interface BadgeProps {
    variant?: keyof typeof variants;
    size?: keyof typeof sizes;
    dot?: boolean;
    children: React.ReactNode;
    className?: string;
}

export function Badge({ variant = 'neutral', size = 'md', dot, children, className }: BadgeProps) {
    return (
        <span className={cn('inline-flex items-center gap-1.5 rounded-full font-medium', variants[variant], sizes[size], className)}>
            {dot && <span className={cn('h-1.5 w-1.5 rounded-full', variants[variant].split(' ')[1])} />}
            {children}
        </span>
    );
}
