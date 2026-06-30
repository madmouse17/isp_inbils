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
    options?: SelectOption[];
}

export const Select = React.forwardRef<HTMLSelectElement, SelectProps>(
    ({ label, error, options, children, className, id, ...props }, ref) => {
        const inputId = id || label?.toLowerCase().replace(/\s+/g, '-');
        return (
            <div className="w-full">
                {label && <Label htmlFor={inputId} required={props.required}>{label}</Label>}
                <div className="relative mt-1">
                    <select
                        ref={ref}
                        id={inputId}
                        className={cn(
                            'w-full appearance-none rounded-lg border border-surface-300 bg-white px-3 py-2 pr-9 text-sm focus:border-brand-500 focus:ring-2 focus:ring-brand-500/30 dark:bg-surface-900 dark:border-surface-700 dark:text-surface-100',
                            error && 'border-danger focus:border-danger focus:ring-danger/30',
                            className,
                        )}
                        aria-invalid={error ? 'true' : undefined}
                        {...props}
                    >
                        {options?.map((o) => (
                            <option key={o.value} value={o.value}>{o.label}</option>
                        ))}
                        {children}
                    </select>
                    <span className="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-surface-400">
                        <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" strokeWidth="2" stroke="currentColor">
                            <path strokeLinecap="round" strokeLinejoin="round" d="M8.25 15L12 18.75 15.75 15m-7.5-6L12 5.25 15.75 9" />
                        </svg>
                    </span>
                </div>
                {error && <p className="mt-1 text-sm text-danger">{error}</p>}
            </div>
        );
    },
);

Select.displayName = 'Select';
