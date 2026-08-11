import * as React from 'react';
import { cn } from '@/lib/utils';

const variants: Record<string, string> = {
    default: 'bg-background text-foreground',
    destructive:
        'border-destructive/50 text-destructive dark:border-destructive [&>svg]:text-destructive',
    danger: 'border-destructive/50 text-destructive dark:border-destructive [&>svg]:text-destructive',
    success: 'border-emerald-500/40 text-emerald-700 dark:text-emerald-400',
    warning: 'border-amber-500/40 text-amber-700 dark:text-amber-400',
    info: 'border-sky-500/40 text-sky-700 dark:text-sky-400',
};

export interface AlertProps extends React.HTMLAttributes<HTMLDivElement> {
    variant?: keyof typeof variants;
    onDismiss?: () => void;
}

const Alert = React.forwardRef<HTMLDivElement, AlertProps>(
    ({ className, variant = 'default', onDismiss: _onDismiss, ...props }, ref) => (
        <div
            ref={ref}
            role="alert"
            className={cn(
                'relative w-full rounded-lg border px-4 py-3 text-sm [&>svg+div]:translate-y-[-3px] [&>svg]:absolute [&>svg]:left-4 [&>svg]:top-4 [&>svg]:text-foreground [&>svg~*]:pl-7',
                variants[variant],
                className,
            )}
            {...props}
        />
    ),
);
Alert.displayName = 'Alert';

const AlertTitle = React.forwardRef<HTMLParagraphElement, React.HTMLAttributes<HTMLHeadingElement>>(
    ({ className, ...props }, ref) => (
        <h5
            ref={ref}
            className={cn('mb-1 font-medium leading-none tracking-tight', className)}
            {...props}
        />
    ),
);
AlertTitle.displayName = 'AlertTitle';

const AlertDescription = React.forwardRef<
    HTMLParagraphElement,
    React.HTMLAttributes<HTMLParagraphElement>
>(({ className, ...props }, ref) => (
    <div ref={ref} className={cn('text-sm [&_p]:leading-relaxed', className)} {...props} />
));
AlertDescription.displayName = 'AlertDescription';

export { Alert, AlertTitle, AlertDescription };
