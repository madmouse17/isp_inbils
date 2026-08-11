import * as React from 'react';
import { cn } from '@/lib/utils';

export interface InputProps extends React.InputHTMLAttributes<HTMLInputElement> {
    label?: string;
    error?: string;
    hint?: string;
    leftIcon?: React.ReactNode;
}

const Input = React.forwardRef<HTMLInputElement, InputProps>(
    ({ className, type, label, error, hint, leftIcon, id, ...props }, ref) => {
        const inputId = id || (label ? label.toLowerCase().replace(/\s+/g, '-') : undefined);
        const describedBy = error ? `${inputId}-error` : hint ? `${inputId}-hint` : undefined;

        return (
            <div className="w-full space-y-1.5">
                {label ? (
                    <label
                        htmlFor={inputId}
                        className="text-sm font-medium leading-none peer-disabled:cursor-not-allowed peer-disabled:opacity-70"
                    >
                        {label}
                        {props.required ? <span className="text-destructive"> *</span> : null}
                    </label>
                ) : null}
                <div className="relative">
                    {leftIcon ? (
                        <span className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-muted-foreground">
                            {leftIcon}
                        </span>
                    ) : null}
                    <input
                        type={type}
                        id={inputId}
                        className={cn(
                            'flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-base shadow-sm transition-colors file:border-0 file:bg-transparent file:text-sm file:font-medium file:text-foreground placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring disabled:cursor-not-allowed disabled:opacity-50 md:text-sm',
                            leftIcon && 'pl-9',
                            error && 'border-destructive focus-visible:ring-destructive',
                            className,
                        )}
                        ref={ref}
                        aria-invalid={error ? true : undefined}
                        aria-describedby={describedBy}
                        {...props}
                    />
                </div>
                {error ? (
                    <p id={`${inputId}-error`} className="text-sm text-destructive">
                        {error}
                    </p>
                ) : null}
                {!error && hint ? (
                    <p id={`${inputId}-hint`} className="text-sm text-muted-foreground">
                        {hint}
                    </p>
                ) : null}
            </div>
        );
    },
);
Input.displayName = 'Input';

export { Input };
