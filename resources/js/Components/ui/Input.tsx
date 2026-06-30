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
                        <span className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-surface-400 dark:text-surface-500">
                            {leftIcon}
                        </span>
                    )}
                    <input
                        ref={ref}
                        id={inputId}
                        className={cn(
                            'w-full rounded-lg border border-surface-300 bg-white px-3 py-2 text-sm placeholder:text-surface-400 focus:border-brand-500 focus:ring-2 focus:ring-brand-500/30 dark:bg-surface-900 dark:border-surface-700 dark:text-surface-100 dark:placeholder:text-surface-500',
                            leftIcon && 'pl-9',
                            error && 'border-danger focus:border-danger focus:ring-danger/30',
                            className,
                        )}
                        aria-invalid={error ? 'true' : undefined}
                        aria-describedby={error ? `${inputId}-error` : hint ? `${inputId}-hint` : undefined}
                        {...props}
                    />
                </div>
                {error && <p id={`${inputId}-error`} className="mt-1 text-sm text-danger">{error}</p>}
                {!error && hint && <p id={`${inputId}-hint`} className="mt-1 text-sm text-surface-500 dark:text-surface-400">{hint}</p>}
            </div>
        );
    },
);

Input.displayName = 'Input';
