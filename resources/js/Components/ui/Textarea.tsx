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
                        'mt-1 min-h-24 w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-sm placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring disabled:cursor-not-allowed disabled:opacity-50',
                        error && 'border-destructive focus-visible:ring-destructive',
                        className,
                    )}
                    aria-invalid={error ? 'true' : undefined}
                    aria-describedby={error ? `${inputId}-error` : hint ? `${inputId}-hint` : undefined}
                    {...props}
                />
                {error && <p id={`${inputId}-error`} className="mt-1 text-sm text-destructive">{error}</p>}
                {!error && hint && <p id={`${inputId}-hint`} className="mt-1 text-sm text-muted-foreground">{hint}</p>}
            </div>
        );
    },
);

Textarea.displayName = 'Textarea';
