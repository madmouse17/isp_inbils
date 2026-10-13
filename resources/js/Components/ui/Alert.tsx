import * as React from 'react';
import { X } from 'lucide-react';
import { cn } from '@/lib/utils';

const variants = {
    default: 'bg-background text-foreground',
    destructive: 'border-destructive-text/40 text-destructive-text [&>svg]:text-destructive-text',
    danger: 'border-destructive-text/40 text-destructive-text [&>svg]:text-destructive-text',
    success: 'border-emerald-500/40 text-emerald-700 dark:text-emerald-400',
    warning: 'border-amber-500/40 text-amber-700 dark:text-amber-400',
    info: 'border-sky-500/40 text-sky-700 dark:text-sky-400',
} as const;

export type AlertVariant = keyof typeof variants;

export interface AlertProps extends React.HTMLAttributes<HTMLDivElement> {
    variant?: AlertVariant;
    /** Renders a close button; the caller removes the alert. */
    onDismiss?: () => void;
}

const Alert = React.forwardRef<HTMLDivElement, AlertProps>(
    ({ className, variant = 'default', onDismiss, children, ...props }, ref) => (
        <div
            ref={ref}
            role="alert"
            className={cn(
                'relative w-full rounded-lg border px-4 py-3 text-sm [&>svg]:absolute [&>svg]:top-4 [&>svg]:left-4 [&>svg]:text-foreground [&>svg+div]:translate-y-[-3px] [&>svg~*]:pl-7',
                onDismiss && 'pr-12',
                variants[variant],
                className,
            )}
            {...props}
        >
            {children}
            {onDismiss ? (
                <button
                    type="button"
                    onClick={onDismiss}
                    aria-label="Tutup peringatan"
                    // `!` beats the icon indent [&>svg~*]:pl-7, which would also reach this last child.
                    className="absolute top-1 right-1 inline-flex h-11 w-11 items-center justify-center rounded-md p-0! text-current hover:bg-accent focus-visible:ring-1 focus-visible:ring-ring focus-visible:outline-hidden sm:h-8 sm:w-8 sm:pointer-coarse:h-11 sm:pointer-coarse:w-11"
                >
                    <X className="h-4 w-4" aria-hidden="true" />
                </button>
            ) : null}
        </div>
    ),
);
Alert.displayName = 'Alert';

const AlertTitle = React.forwardRef<HTMLParagraphElement, React.HTMLAttributes<HTMLHeadingElement>>(
    ({ className, ...props }, ref) => (
        <h5
            ref={ref}
            className={cn('mb-1 leading-none font-medium tracking-tight', className)}
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
