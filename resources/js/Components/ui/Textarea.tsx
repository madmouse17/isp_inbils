import React from 'react';
import { cn } from '@/lib/utils';
import { Label } from './Label';

export interface TextareaProps extends React.TextareaHTMLAttributes<HTMLTextAreaElement> {
    label?: string;
    error?: string;
    hint?: string;
}

export const Textarea = React.forwardRef<HTMLTextAreaElement, TextareaProps>(
    ({ label, error, hint, className, id, rows = 3, ...props }, ref) => {
        const inputId = id || label?.toLowerCase().replace(/\s+/g, '-');
        return (
            <div className="w-full">
                {label && <Label htmlFor={inputId} required={props.required}>{label}</Label>}
                <textarea
                    ref={ref}
                    id={inputId}
                    rows={rows}
                    className={cn(
                        'mt-1 w-full rounded-lg border border-surface-300 bg-white px-3 py-2 text-sm placeholder:text-surface-400 focus:border-brand-500 focus:ring-2 focus:ring-brand-500/30 dark:bg-surface-900 dark:border-surface-700 dark:text-surface-100 dark:placeholder:text-surface-500',
                        error && 'border-danger focus:border-danger focus:ring-danger/30',
                        className,
                    )}
                    aria-invalid={error ? 'true' : undefined}
                    aria-describedby={error ? `${inputId}-error` : hint ? `${inputId}-hint` : undefined}
                    {...props}
                />
                {error && <p id={`${inputId}-error`} className="mt-1 text-sm text-danger">{error}</p>}
                {!error && hint && <p id={`${inputId}-hint`} className="mt-1 text-sm text-surface-500 dark:text-surface-400">{hint}</p>}
            </div>
        );
    },
);

Textarea.displayName = 'Textarea';
