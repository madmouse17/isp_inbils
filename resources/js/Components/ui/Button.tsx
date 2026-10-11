import React from 'react';
import { Slot, Slottable } from '@radix-ui/react-slot';
import { cn } from '@/lib/utils';
import { Spinner } from './Spinner';

const buttonVariantClasses = {
    default: 'bg-primary text-primary-foreground shadow-sm hover:bg-primary/90',
    primary: 'bg-primary text-primary-foreground shadow-sm hover:bg-primary/90',
    secondary: 'bg-secondary text-secondary-foreground hover:bg-secondary/80',
    success: 'bg-success text-white shadow-xs hover:bg-success/90',
    warning: 'bg-warning text-white shadow-xs hover:bg-warning/90',
    ghost: 'hover:bg-accent hover:text-accent-foreground',
    danger: 'bg-red-600 text-white shadow-xs hover:bg-red-700 dark:bg-destructive dark:text-destructive-foreground dark:hover:bg-destructive/90',
    destructive:
        'bg-red-600 text-white shadow-xs hover:bg-red-700 dark:bg-destructive dark:text-destructive-foreground dark:hover:bg-destructive/90',
    outline:
        'border border-input bg-background shadow-xs hover:bg-accent hover:text-accent-foreground',
} as const;

export function buttonVariants(options?: {
    variant?: keyof typeof buttonVariantClasses;
    size?: 'sm' | 'md' | 'lg' | 'icon';
    className?: string;
}) {
    const variant = options?.variant ?? 'default';
    const size = options?.size ?? 'md';
    return cn(
        'inline-flex items-center justify-center gap-2 rounded-md font-medium whitespace-nowrap transition-colors focus-visible:ring-1 focus-visible:ring-ring focus-visible:outline-hidden disabled:pointer-events-none disabled:opacity-50',
        buttonVariantClasses[variant],
        sizes[size],
        options?.className,
    );
}

const sizes = {
    sm: 'h-8 rounded-md px-3 text-xs',
    md: 'h-9 px-4 py-2 text-sm',
    lg: 'h-10 rounded-md px-8 text-sm',
    icon: 'h-9 w-9',
} as const;

export interface ButtonProps extends React.ButtonHTMLAttributes<HTMLButtonElement> {
    variant?: keyof typeof buttonVariantClasses;
    size?: 'sm' | 'md' | 'lg' | 'icon';
    loading?: boolean;
    leftIcon?: React.ReactNode;
    rightIcon?: React.ReactNode;
    asChild?: boolean;
}

export const Button = React.forwardRef<HTMLButtonElement, ButtonProps>(
    (
        {
            variant = 'default',
            size = 'md',
            loading = false,
            leftIcon,
            rightIcon,
            className,
            disabled,
            asChild = false,
            children,
            type,
            ...props
        },
        ref,
    ) => {
        const Comp = asChild ? Slot : 'button';

        return (
            <Comp
                ref={ref}
                type={asChild ? undefined : (type ?? 'button')}
                className={cn(
                    'inline-flex items-center justify-center gap-2 rounded-md font-medium whitespace-nowrap transition-colors focus-visible:ring-1 focus-visible:ring-ring focus-visible:outline-hidden disabled:pointer-events-none disabled:opacity-50',
                    buttonVariantClasses[variant],
                    sizes[size],
                    className,
                )}
                disabled={disabled || loading}
                aria-busy={loading || undefined}
                {...props}
            >
                {loading && <Spinner size="sm" />}
                {!loading && leftIcon}
                {asChild ? <Slottable>{children}</Slottable> : children}
                {!loading && rightIcon}
            </Comp>
        );
    },
);

Button.displayName = 'Button';
