import * as React from 'react';
import { cn } from '@/lib/utils';
import { controlHeight } from './controlSize';

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
            <div className="space-y-1.5 w-full">
                {label ? (
                    <label
                        htmlFor={inputId}
                        className="text-sm leading-none font-medium peer-disabled:cursor-not-allowed peer-disabled:opacity-70"
                    >
                        {label}
                        {props.required ? (
                            <span aria-hidden="true" className="text-destructive-text">
                                {' '}
                                *
                            </span>
                        ) : null}
                    </label>
                ) : null}
                <div className="relative">
                    {leftIcon ? (
                        <span className="pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-muted-foreground">
                            {leftIcon}
                        </span>
                    ) : null}
                    <input
                        type={type}
                        id={inputId}
                        className={cn(
                            'flex w-full rounded-md border border-input bg-transparent px-3 py-1 text-base transition-colors file:border-0 file:bg-transparent file:text-sm file:font-medium file:text-foreground placeholder:text-muted-foreground focus-visible:ring-1 focus-visible:ring-ring focus-visible:outline-hidden disabled:cursor-not-allowed disabled:opacity-50 md:text-sm',
                            controlHeight.md,
                            leftIcon && 'pl-9',
                            error && 'border-destructive-text focus-visible:ring-destructive-text',
                            className,
                        )}
                        ref={ref}
                        aria-invalid={error ? true : undefined}
                        aria-describedby={describedBy}
                        {...props}
                    />
                </div>
                {error ? (
                    <p id={`${inputId}-error`} className="text-sm text-destructive-text">
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
