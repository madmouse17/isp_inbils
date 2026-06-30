import React from 'react';
import { cn } from '@/lib/utils';

const variants = {
    primary: 'bg-brand-600 text-white hover:bg-brand-700 active:bg-brand-800',
    secondary: 'bg-surface-100 text-surface-700 hover:bg-surface-200 active:bg-surface-300 dark:bg-surface-800 dark:text-surface-200 dark:hover:bg-surface-700 dark:active:bg-surface-600',
    ghost: 'bg-transparent text-surface-700 hover:bg-surface-100 active:bg-surface-200 dark:text-surface-300 dark:hover:bg-surface-800 dark:active:bg-surface-700',
    danger: 'bg-danger text-white hover:opacity-90 active:opacity-80',
} as const;

const sizes = {
    sm: 'h-8 w-8',
    md: 'h-9 w-9',
    lg: 'h-10 w-10',
} as const;

export interface IconButtonProps extends React.ButtonHTMLAttributes<HTMLButtonElement> {
    label: string;
    variant?: keyof typeof variants;
    size?: keyof typeof sizes;
}

export const IconButton = React.forwardRef<HTMLButtonElement, IconButtonProps>(
    ({ label, variant = 'ghost', size = 'md', className, children, ...props }, ref) => (
        <button
            ref={ref}
            aria-label={label}
            className={cn(
                'inline-flex items-center justify-center rounded-lg transition-colors focus:outline-none focus:ring-2 focus:ring-brand-500/50 disabled:opacity-60 disabled:cursor-not-allowed',
                variants[variant],
                sizes[size],
                className,
            )}
            {...props}
        >
            {children}
        </button>
    ),
);

IconButton.displayName = 'IconButton';
