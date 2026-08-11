import * as React from 'react';
import { cn } from '@/lib/utils';

export interface CheckboxProps extends Omit<
    React.InputHTMLAttributes<HTMLInputElement>,
    'type' | 'onChange'
> {
    label?: string;
    description?: string;
    error?: string;
    indeterminate?: boolean;
    onCheckedChange?: (checked: boolean | 'indeterminate') => void;
    onChange?: (event: React.ChangeEvent<HTMLInputElement>) => void;
}

const Checkbox = React.forwardRef<HTMLInputElement, CheckboxProps>(
    (
        {
            className,
            label,
            description,
            error,
            indeterminate = false,
            checked,
            defaultChecked,
            disabled,
            id,
            onCheckedChange,
            onChange,
            ...props
        },
        ref,
    ) => {
        const inputRef = React.useRef<HTMLInputElement | null>(null);
        const checkboxId = id || (label ? label.toLowerCase().replace(/\s+/g, '-') : undefined);

        React.useImperativeHandle(ref, () => inputRef.current as HTMLInputElement);

        React.useEffect(() => {
            if (inputRef.current) {
                inputRef.current.indeterminate = indeterminate;
            }
        }, [indeterminate]);

        return (
            <div className="flex flex-col gap-1">
                <label
                    htmlFor={checkboxId}
                    className={cn(
                        'inline-flex items-center gap-2 text-sm',
                        disabled && 'cursor-not-allowed opacity-50',
                    )}
                >
                    <input
                        ref={inputRef}
                        id={checkboxId}
                        type="checkbox"
                        className={cn(
                            'h-4 w-4 rounded border border-primary text-primary shadow focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring disabled:cursor-not-allowed disabled:opacity-50',
                            error && 'border-destructive',
                            className,
                        )}
                        checked={checked}
                        defaultChecked={defaultChecked}
                        disabled={disabled}
                        aria-invalid={error ? true : undefined}
                        onChange={(event) => {
                            onChange?.(event);
                            onCheckedChange?.(event.target.checked);
                        }}
                        {...props}
                    />
                    {label || description ? (
                        <span>
                            {label}
                            {description ? (
                                <span className="block text-xs text-muted-foreground">
                                    {description}
                                </span>
                            ) : null}
                        </span>
                    ) : null}
                </label>
                {error ? <p className="text-sm text-destructive">{error}</p> : null}
            </div>
        );
    },
);
Checkbox.displayName = 'Checkbox';

export { Checkbox };
