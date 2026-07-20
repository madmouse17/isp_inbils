import React from 'react';
import { cn } from '@/lib/utils';
import { Label } from './Label';

interface SelectOption {
    value: string;
    label: string;
}

export interface SelectProps extends React.SelectHTMLAttributes<HTMLSelectElement> {
    label?: string;
    error?: string;
    hint?: string;
    options?: SelectOption[];
}

export const Select = React.forwardRef<HTMLSelectElement, SelectProps>(
    ({ label, error, hint, options, children, className, id, ...props }, ref) => {
        const inputId = id || label?.toLowerCase().replace(/\s+/g, '-');
        return (
            <div className="w-full">
                {label && (
                    <Label htmlFor={inputId} required={props.required}>
                        {label}
                    </Label>
                )}
                <div className="relative mt-1">
                    <select
                        ref={ref}
                        id={inputId}
                        className={cn(
                            'h-9 w-full appearance-none rounded-md border border-input bg-background px-3 py-1 pr-9 text-sm text-foreground shadow-sm transition-colors [color-scheme:light] focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring disabled:cursor-not-allowed disabled:opacity-50 dark:[color-scheme:dark] [&>option:disabled]:text-muted-foreground [&>option]:bg-background [&>option]:text-foreground',
                            error && 'border-destructive focus-visible:ring-destructive',
                            className,
                        )}
                        aria-invalid={error ? 'true' : undefined}
                        aria-describedby={
                            error ? `${inputId}-error` : hint ? `${inputId}-hint` : undefined
                        }
                        {...props}
                    >
                        {options?.map((o) => (
                            <option key={o.value} value={o.value}>
                                {o.label}
                            </option>
                        ))}
                        {children}
                    </select>
                    <span className="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-muted-foreground">
                        <svg
                            className="h-4 w-4"
                            fill="none"
                            viewBox="0 0 24 24"
                            strokeWidth="2"
                            stroke="currentColor"
                        >
                            <path
                                strokeLinecap="round"
                                strokeLinejoin="round"
                                d="M8.25 15L12 18.75 15.75 15m-7.5-6L12 5.25 15.75 9"
                            />
                        </svg>
                    </span>
                </div>
                {error && (
                    <p id={`${inputId}-error`} className="mt-1 text-sm text-destructive">
                        {error}
                    </p>
                )}
                {!error && hint && (
                    <p id={`${inputId}-hint`} className="mt-1 text-sm text-muted-foreground">
                        {hint}
                    </p>
                )}
            </div>
        );
    },
);

Select.displayName = 'Select';
