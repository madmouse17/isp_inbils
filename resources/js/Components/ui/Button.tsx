import React from 'react';
import { Slot, Slottable } from '@radix-ui/react-slot';
import { cn } from '@/lib/utils';
import { controlHeight, controlSquare } from './controlSize';
import { Spinner } from './Spinner';

/*
 * Filled variants (`default`/`primary`, `destructive`/`danger`) are for one primary action and for destructive
 * confirmations: white text on the fill. Every other action is `outline` (brand border and text) or `secondary`
 * (the same with a tint); a delete-like secondary action is `destructive-outline`. `ghost` is for icon-only
 * toolbar buttons. Text reaches 4.5:1 and borders 3:1 against the page, the muted surfaces and both themes.
 * Dark hover lightens the destructive fill and blends the primary fill into the page: either way the fill keeps
 * 3:1 against the page and the label 4.5:1 on the fill.
 */
export const buttonVariantClasses = {
    default: 'bg-primary text-primary-foreground hover:bg-brand-700 dark:hover:bg-primary/90',
    primary: 'bg-primary text-primary-foreground hover:bg-brand-700 dark:hover:bg-primary/90',
    secondary:
        'border border-primary-text/70 bg-primary/10 text-primary-text hover:bg-primary/20 dark:bg-primary/15 dark:hover:bg-primary/25',
    ghost: 'hover:bg-accent hover:text-accent-foreground',
    danger: 'bg-destructive text-destructive-foreground hover:bg-red-700 dark:hover:bg-red-600',
    destructive:
        'bg-destructive text-destructive-foreground hover:bg-red-700 dark:hover:bg-red-600',
    outline:
        'border border-primary-text bg-transparent text-primary-text hover:bg-primary/10 dark:hover:bg-primary/15',
    'destructive-outline':
        'border border-destructive-text bg-transparent text-destructive-text hover:bg-destructive/10 dark:hover:bg-destructive/15',
    'danger-outline':
        'border border-destructive-text bg-transparent text-destructive-text hover:bg-destructive/10 dark:hover:bg-destructive/15',
} as const;

const sizes = {
    sm: `${controlHeight.sm} rounded-md px-3 text-xs`,
    md: `${controlHeight.md} px-4 py-2 text-sm`,
    lg: `${controlHeight.lg} rounded-md px-8 text-sm`,
    // Content decides the height (multi-line labels); only the touch minimum applies.
    auto: 'min-h-11 px-4 py-2 text-sm sm:min-h-9',
    icon: controlSquare,
} as const;

const baseClasses =
    'inline-flex items-center justify-center gap-2 rounded-md font-medium whitespace-nowrap transition-colors focus-visible:ring-1 focus-visible:ring-ring focus-visible:outline-hidden disabled:pointer-events-none disabled:opacity-50';

export function buttonVariants(options?: {
    variant?: keyof typeof buttonVariantClasses;
    size?: keyof typeof sizes;
    className?: string;
}) {
    const variant = options?.variant ?? 'default';
    const size = options?.size ?? 'md';
    return cn(baseClasses, buttonVariantClasses[variant], sizes[size], options?.className);
}

export interface ButtonProps extends React.ButtonHTMLAttributes<HTMLButtonElement> {
    variant?: keyof typeof buttonVariantClasses;
    size?: keyof typeof sizes;
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
                className={cn(baseClasses, buttonVariantClasses[variant], sizes[size], className)}
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
