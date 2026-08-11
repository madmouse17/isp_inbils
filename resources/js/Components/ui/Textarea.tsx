import * as React from 'react';
import { cn } from '@/lib/utils';

export interface TextareaProps extends React.TextareaHTMLAttributes<HTMLTextAreaElement> {
    label?: string;
    error?: string;
    hint?: string;
}

const Textarea = React.forwardRef<HTMLTextAreaElement, TextareaProps>(
    ({ className, label, error, hint, id, ...props }, ref) => {
        const areaId = id || (label ? label.toLowerCase().replace(/\s+/g, '-') : undefined);
        const describedBy = error ? `${areaId}-error` : hint ? `${areaId}-hint` : undefined;

        return (
            <div className="w-full space-y-1.5">
                {label ? (
                    <label
                        htmlFor={areaId}
                        className="text-sm font-medium leading-none peer-disabled:cursor-not-allowed peer-disabled:opacity-70"
                    >
                        {label}
                        {props.required ? <span className="text-destructive"> *</span> : null}
                    </label>
                ) : null}
                <textarea
                    id={areaId}
                    className={cn(
                        'flex min-h-[60px] w-full rounded-md border border-input bg-transparent px-3 py-2 text-base shadow-sm placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring disabled:cursor-not-allowed disabled:opacity-50 md:text-sm',
                        error && 'border-destructive focus-visible:ring-destructive',
                        className,
                    )}
                    ref={ref}
                    aria-invalid={error ? true : undefined}
                    aria-describedby={describedBy}
                    {...props}
                />
                {error ? (
                    <p id={`${areaId}-error`} className="text-sm text-destructive">
                        {error}
                    </p>
                ) : null}
                {!error && hint ? (
                    <p id={`${areaId}-hint`} className="text-sm text-muted-foreground">
                        {hint}
                    </p>
                ) : null}
            </div>
        );
    },
);
Textarea.displayName = 'Textarea';

export { Textarea };
