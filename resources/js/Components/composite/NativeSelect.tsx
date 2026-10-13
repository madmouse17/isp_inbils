import React from 'react';
import { ChevronsUpDown } from 'lucide-react';
import { cn } from '@/lib/utils';
import { Label } from '@/Components/ui/Label';
import { controlHeight } from '@/Components/ui/controlSize';

interface NativeSelectOption {
    value: string;
    label: string;
}

export interface NativeSelectProps extends React.SelectHTMLAttributes<HTMLSelectElement> {
    label?: string;
    error?: string;
    hint?: string;
    options?: NativeSelectOption[];
    /** Width and spacing of the whole control. `className` only reaches the <select>. */
    wrapperClassName?: string;
}

// ponytail: plain <select> ceiling for short, fixed option lists. Lists read from the database use SearchSelect.
export const NativeSelect = React.forwardRef<HTMLSelectElement, NativeSelectProps>(
    ({ label, error, hint, options, children, className, wrapperClassName, id, ...props }, ref) => {
        const inputId = id || label?.toLowerCase().replace(/\s+/g, '-');
        return (
            <div className={cn('w-full', wrapperClassName)}>
                {label && (
                    <Label htmlFor={inputId} required={props.required}>
                        {label}
                    </Label>
                )}
                <div className={cn('relative', label && 'mt-1')}>
                    <select
                        ref={ref}
                        id={inputId}
                        className={cn(
                            // bg-none: @tailwindcss/forms paints its own chevron, which would sit under this one.
                            'w-full appearance-none rounded-md border border-input bg-background bg-none px-3 py-1 pr-9 text-sm text-foreground scheme-light transition-colors focus-visible:ring-1 focus-visible:ring-ring focus-visible:outline-hidden disabled:cursor-not-allowed disabled:opacity-50 dark:scheme-dark [&>option]:bg-background [&>option]:text-foreground [&>option:disabled]:text-muted-foreground',
                            controlHeight.md,
                            error && 'border-destructive-text focus-visible:ring-destructive-text',
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
                    <ChevronsUpDown
                        className="pointer-events-none absolute top-1/2 right-3 h-4 w-4 -translate-y-1/2 text-muted-foreground"
                        aria-hidden="true"
                    />
                </div>
                {error && (
                    <p id={`${inputId}-error`} className="mt-1 text-sm text-destructive-text">
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
NativeSelect.displayName = 'NativeSelect';
