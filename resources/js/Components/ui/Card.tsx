import { cn } from '@/lib/utils';

interface CardProps extends React.HTMLAttributes<HTMLDivElement> {}

export function Card({ className, children, ...props }: CardProps) {
    return (
        <div
            className={cn('rounded-xl border border-surface-200 bg-white shadow-sm dark:bg-surface-900 dark:border-surface-800', className)}
            {...props}
        >
            {children}
        </div>
    );
}

interface CardHeaderProps extends React.HTMLAttributes<HTMLDivElement> {}

export function CardHeader({ className, ...props }: CardHeaderProps) {
    return <div className={cn('px-5 py-4', className)} {...props} />;
}

interface CardTitleProps extends React.HTMLAttributes<HTMLHeadingElement> {}

export function CardTitle({ className, ...props }: CardTitleProps) {
    return <h3 className={cn('font-semibold text-surface-900 dark:text-surface-100', className)} {...props} />;
}

interface CardDescriptionProps extends React.HTMLAttributes<HTMLParagraphElement> {}

export function CardDescription({ className, ...props }: CardDescriptionProps) {
    return <p className={cn('mt-1 text-sm text-surface-500 dark:text-surface-400', className)} {...props} />;
}

interface CardContentProps extends React.HTMLAttributes<HTMLDivElement> {}

export function CardContent({ className, ...props }: CardContentProps) {
    return <div className={cn('px-5 py-4', className)} {...props} />;
}

interface CardFooterProps extends React.HTMLAttributes<HTMLDivElement> {}

export function CardFooter({ className, ...props }: CardFooterProps) {
    return <div className={cn('px-5 py-4 border-t border-surface-200 dark:border-surface-800', className)} {...props} />;
}
