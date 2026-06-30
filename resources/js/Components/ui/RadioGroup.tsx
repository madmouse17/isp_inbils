import React, { createContext, useContext } from 'react';
import { cn } from '@/lib/utils';

interface RadioContextValue {
    name: string;
    value?: string;
    onChange?: (value: string) => void;
}

const RadioContext = createContext<RadioContextValue | null>(null);

interface RadioGroupProps {
    name: string;
    value?: string;
    onChange?: (value: string) => void;
    label?: string;
    children: React.ReactNode;
    className?: string;
}

export function RadioGroup({ name, value, onChange, label, children, className }: RadioGroupProps) {
    return (
        <RadioContext.Provider value={{ name, value, onChange }}>
            <fieldset className={cn('space-y-2', className)} role="radiogroup" aria-label={label}>
                {label && (
                    <legend className="text-sm font-medium text-surface-700 dark:text-surface-300">{label}</legend>
                )}
                {children}
            </fieldset>
        </RadioContext.Provider>
    );
}

interface RadioProps extends Omit<React.InputHTMLAttributes<HTMLInputElement>, 'type'> {
    value: string;
    label?: string;
    description?: string;
}

export const Radio = React.forwardRef<HTMLInputElement, RadioProps>(
    ({ value, label, description, className, ...props }, ref) => {
        const ctx = useContext(RadioContext);
        const inputId = `radio-${ctx?.name}-${value}`;
        return (
            <div className="flex items-start gap-3">
                <input
                    ref={ref}
                    type="radio"
                    id={inputId}
                    name={ctx?.name}
                    value={value}
                    checked={ctx?.value === value}
                    onChange={() => ctx?.onChange?.(value)}
                    className={cn(
                        'mt-0.5 h-4 w-4 border-surface-300 text-brand-600 focus:ring-2 focus:ring-brand-500/30 dark:border-surface-700 dark:bg-surface-900',
                        className,
                    )}
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
                    </div>
                )}
            </div>
        );
    },
);

Radio.displayName = 'Radio';
