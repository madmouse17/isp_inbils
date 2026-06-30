import React from 'react';
import { cn } from '@/lib/utils';

const variants = {
    primary: 'bg-brand-600 text-white hover:bg-brand-700 active:bg-brand-800',
    secondary: 'bg-surface-100 text-surface-700 hover:bg-surface-200 active:bg-surface-300 dark:bg-surface-800 dark:text-surface-200 dark:hover:bg-surface-700 dark:active:bg-surface-600',
    ghost: 'bg-transparent text-surface-700 hover:bg-surface-100 active:bg-surface-200 dark:text-surface-300 dark:hover:bg-surface-800 dark:active:bg-surface-700',
    danger: 'bg-danger text-white hover:opacity-90 active:opacity-80',
    outline: 'border border-surface-300 bg-transparent text-surface-700 hover:bg-surface-50 active:bg-surface-100 dark:border-surface-700 dark:text-surface-300 dark:hover:bg-surface-800 dark:active:bg-surface-700',
} as const;

const sizes = {
    sm: 'px-3 py-1.5 text-sm',
    md: 'px-4 py-2 text-sm',
    lg: 'px-5 py-2.5 text-base',
} as const;

export interface ButtonProps extends React.ButtonHTMLAttributes<HTMLButtonElement> {
    variant?: keyof typeof variants;
    size?: keyof typeof sizes;
    loading?: boolean;
    leftIcon?: React.ReactNode;
    rightIcon?: React.ReactNode;
}

export const Button = React.forwardRef<HTMLButtonElement, ButtonProps>(
    ({ variant = 'primary', size = 'md', loading = false, leftIcon, rightIcon, className, disabled, children, ...props }, ref) => (
        <button
            ref={ref}
            className={cn(
                'inline-flex items-center justify-center gap-2 rounded-lg font-medium transition-colors focus:outline-none focus:ring-2 focus:ring-brand-500/50 disabled:opacity-60 disabled:cursor-not-allowed',
                variants[variant],
                sizes[size],
                className,
            )}
            disabled={disabled || loading}
            {...props}
        >
            {loading && <Spinner />}
            {!loading && leftIcon}
            {children}
            {!loading && rightIcon}
        </button>
    ),
);

Button.displayName = 'Button';

function Spinner() {
    return (
        <svg className="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none">
            <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
            <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
        </svg>
    );
}
