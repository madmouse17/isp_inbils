import React from 'react';
import { Slot, Slottable } from '@radix-ui/react-slot';
import { cn } from '@/lib/utils';

const buttonVariantClasses = {
    default: 'bg-primary text-primary-foreground shadow hover:bg-primary/90',
    primary: 'bg-primary text-primary-foreground shadow hover:bg-primary/90',
    secondary: 'bg-secondary text-secondary-foreground hover:bg-secondary/80',
    ghost: 'hover:bg-accent hover:text-accent-foreground',
    danger: 'bg-destructive text-destructive-foreground shadow-sm hover:bg-destructive/90',
    destructive: 'bg-destructive text-destructive-foreground shadow-sm hover:bg-destructive/90',
    outline:
        'border border-input bg-background shadow-sm hover:bg-accent hover:text-accent-foreground',
} as const;

export function buttonVariants(options?: {
    variant?: keyof typeof buttonVariantClasses;
    size?: 'sm' | 'md' | 'lg' | 'icon';
    className?: string;
}) {
    const variant = options?.variant ?? 'default';
    const size = options?.size ?? 'md';
    return cn(
        'inline-flex items-center justify-center gap-2 whitespace-nowrap rounded-md font-medium transition-colors focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring disabled:pointer-events-none disabled:opacity-50',
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
                    'inline-flex items-center justify-center gap-2 whitespace-nowrap rounded-md font-medium transition-colors focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring disabled:pointer-events-none disabled:opacity-50',
                    buttonVariantClasses[variant],
                    sizes[size],
                    className,
                )}
                disabled={disabled || loading}
                {...props}
            >
                {loading && <Spinner />}
                {!loading && leftIcon}
                {asChild ? <Slottable>{children}</Slottable> : children}
                {!loading && rightIcon}
            </Comp>
        );
    },
);

Button.displayName = 'Button';

function Spinner() {
    return (
        <svg className="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none">
            <circle
                className="opacity-25"
                cx="12"
                cy="12"
                r="10"
                stroke="currentColor"
                strokeWidth="4"
            />
            <path
                className="opacity-75"
                fill="currentColor"
                d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"
            />
        </svg>
    );
}
