import React from 'react';
import { cn } from '@/lib/utils';
import { Label } from './Label';

export interface InputProps extends React.InputHTMLAttributes<HTMLInputElement> {
    label?: string;
    error?: string;
    hint?: string;
    leftIcon?: React.ReactNode;
}

export const Input = React.forwardRef<HTMLInputElement, InputProps>(
    ({ label, error, hint, leftIcon, className, id, ...props }, ref) => {
        const inputId = id || label?.toLowerCase().replace(/\s+/g, '-');
        return (
            <div className="w-full">
                {label && <Label htmlFor={inputId} required={props.required}>{label}</Label>}
                <div className="relative mt-1">
                    {leftIcon && (
                        <span className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-muted-foreground">
                            {leftIcon}
                        </span>
                    )}
                    <input
                        ref={ref}
                        id={inputId}
                        className={cn(
                            'h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-sm transition-colors placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring disabled:cursor-not-allowed disabled:opacity-50',
                            leftIcon && 'pl-9',
                            error && 'border-destructive focus-visible:ring-destructive',
                            className,
                        )}
                        aria-invalid={error ? 'true' : undefined}
                        aria-describedby={error ? `${inputId}-error` : hint ? `${inputId}-hint` : undefined}
                        {...props}
                    />
                </div>
                {error && <p id={`${inputId}-error`} className="mt-1 text-sm text-destructive">{error}</p>}
                {!error && hint && <p id={`${inputId}-hint`} className="mt-1 text-sm text-muted-foreground">{hint}</p>}
            </div>
        );
    },
);

Input.displayName = 'Input';
