import React from 'react';
import { cn } from '@/lib/utils';

const variants = {
    primary: 'bg-primary text-primary-foreground shadow hover:bg-primary/90',
    secondary: 'bg-secondary text-secondary-foreground hover:bg-secondary/80',
    ghost: 'hover:bg-accent hover:text-accent-foreground',
    danger: 'bg-destructive text-destructive-foreground shadow-sm hover:bg-destructive/90',
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
                'inline-flex items-center justify-center rounded-md transition-colors focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring disabled:pointer-events-none disabled:opacity-50',
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
