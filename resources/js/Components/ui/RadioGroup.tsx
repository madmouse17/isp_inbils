import React, { createContext, useContext, useId } from 'react';
import { cn } from '@/lib/utils';

interface RadioContextValue {
    name: string;
    value?: string;
    onChange?: (value: string) => void;
    invalid: boolean;
}

const RadioContext = createContext<RadioContextValue | null>(null);

interface RadioGroupProps {
    name: string;
    value?: string;
    onChange?: (value: string) => void;
    label?: string;
    /** Keeps the label for assistive technology only, when a heading right above already names the group. */
    hideLabel?: boolean;
    /** Shown under the options. Marks the group invalid for assistive technology and outlines every radio. */
    error?: string;
    children: React.ReactNode;
    className?: string;
}

export function RadioGroup({
    name,
    value,
    onChange,
    label,
    hideLabel,
    error,
    children,
    className,
}: RadioGroupProps) {
    const errorId = useId();

    return (
        <RadioContext.Provider value={{ name, value, onChange, invalid: Boolean(error) }}>
            <fieldset
                className={cn('space-y-2', className)}
                role="radiogroup"
                aria-label={label}
                aria-invalid={error ? true : undefined}
                aria-describedby={error ? errorId : undefined}
            >
                {label && (
                    <legend
                        className={cn(
                            'text-sm font-medium text-foreground',
                            hideLabel && 'sr-only',
                        )}
                    >
                        {label}
                    </legend>
                )}
                {children}
                {error ? (
                    <p id={errorId} className="text-sm text-destructive-text">
                        {error}
                    </p>
                ) : null}
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
    ({ value, label, description, className, disabled, id, ...props }, ref) => {
        const ctx = useContext(RadioContext);
        const textId = useId();
        const labelId = `${textId}-label`;
        const descriptionId = `${textId}-description`;
        const inputId = id ?? `radio-${ctx?.name}-${value}`;

        const input = (
            <input
                ref={ref}
                type="radio"
                id={inputId}
                name={ctx?.name}
                value={value}
                checked={ctx?.value === value}
                disabled={disabled}
                onChange={() => ctx?.onChange?.(value)}
                aria-labelledby={label ? labelId : undefined}
                aria-describedby={description ? descriptionId : undefined}
                className={cn(
                    'mt-0.5 h-4 w-4 shrink-0 border-input text-primary focus-visible:ring-1 focus-visible:ring-ring disabled:cursor-not-allowed',
                    ctx?.invalid && 'border-destructive-text',
                    className,
                )}
                {...props}
            />
        );

        if (!label && !description) {
            return <div className="flex items-start gap-3">{input}</div>;
        }

        // The whole row is the label, so a tap anywhere on it picks the option; it is 44px high on a touch screen.
        return (
            <label
                htmlFor={inputId}
                className={cn(
                    'flex min-h-11 items-start gap-3 py-3 sm:min-h-0 sm:py-0 sm:pointer-coarse:min-h-11 sm:pointer-coarse:py-3',
                    disabled ? 'cursor-not-allowed opacity-50' : 'cursor-pointer',
                )}
            >
                {input}
                <span className="flex flex-col">
                    {label && (
                        <span id={labelId} className="text-sm font-medium text-foreground">
                            {label}
                        </span>
                    )}
                    {description && (
                        <span id={descriptionId} className="text-sm text-muted-foreground">
                            {description}
                        </span>
                    )}
                </span>
            </label>
        );
    },
);

Radio.displayName = 'Radio';
