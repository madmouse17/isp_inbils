import React from 'react';
import { cn } from '@/lib/utils';

export interface CheckboxProps extends Omit<React.InputHTMLAttributes<HTMLInputElement>, 'type'> {
    label?: string;
    description?: string;
    error?: string;
}

export const Checkbox = React.forwardRef<HTMLInputElement, CheckboxProps>(
    ({ label, description, error, className, id, ...props }, ref) => {
        const inputId = id || label?.toLowerCase().replace(/\s+/g, '-');
        return (
            <div className="flex items-start gap-3">
                <input
                    ref={ref}
                    type="checkbox"
                    id={inputId}
                    className={cn(
                        'mt-0.5 h-4 w-4 rounded border-input text-primary focus-visible:ring-1 focus-visible:ring-ring',
                        error && 'border-destructive',
                        className,
                    )}
                    aria-invalid={error ? 'true' : undefined}
                    {...props}
                />
                {(label || description) && (
                    <div>
                        {label && (
                            <label
                                htmlFor={inputId}
                                className="text-sm font-medium text-foreground"
                            >
                                {label}
                            </label>
                        )}
                        {description && (
                            <p className="text-sm text-muted-foreground">{description}</p>
                        )}
                        {error && <p className="text-sm text-destructive">{error}</p>}
                    </div>
                )}
            </div>
        );
    },
);

Checkbox.displayName = 'Checkbox';
