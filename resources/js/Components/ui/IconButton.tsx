import React from 'react';
import { cn } from '@/lib/utils';
import { controlSquare } from './controlSize';
import { buttonVariantClasses } from './Button';

const variants = buttonVariantClasses;

const sizes = {
    sm: 'h-11 w-11 sm:h-8 sm:w-8 sm:pointer-coarse:h-11 sm:pointer-coarse:w-11',
    md: controlSquare,
    lg: 'h-11 w-11 sm:h-10 sm:w-10 sm:pointer-coarse:h-11 sm:pointer-coarse:w-11',
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
                'inline-flex items-center justify-center rounded-md transition-colors focus-visible:ring-1 focus-visible:ring-ring focus-visible:outline-hidden disabled:pointer-events-none disabled:opacity-50',
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
