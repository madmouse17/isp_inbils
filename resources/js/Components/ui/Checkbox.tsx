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
                        'mt-0.5 h-4 w-4 rounded border-surface-300 text-brand-600 focus:ring-2 focus:ring-brand-500/30 dark:border-surface-700 dark:bg-surface-900',
                        error && 'border-danger',
                        className,
                    )}
                    aria-invalid={error ? 'true' : undefined}
                    {...props}
                />
                {(label || description) && (
                    <div>
                        {label && (
                            <label htmlFor={inputId} className="text-sm font-medium text-surface-700 dark:text-surface-300">
                                {label}
                            </label>
                        )}
                        {description && (
                            <p className="text-sm text-surface-500 dark:text-surface-400">{description}</p>
                        )}
                        {error && <p className="text-sm text-danger">{error}</p>}
                    </div>
                )}
            </div>
        );
    },
);

Checkbox.displayName = 'Checkbox';
