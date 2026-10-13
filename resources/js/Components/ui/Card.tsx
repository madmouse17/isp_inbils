import { cn } from '@/lib/utils';

type CardProps = React.HTMLAttributes<HTMLDivElement>;

export function Card({ className, children, ...props }: CardProps) {
    return (
        <div className={cn('rounded-lg border bg-card text-card-foreground', className)} {...props}>
            {children}
        </div>
    );
}

type CardHeaderProps = React.HTMLAttributes<HTMLDivElement>;

export function CardHeader({ className, ...props }: CardHeaderProps) {
    return <div className={cn('space-y-1.5 flex flex-col p-6', className)} {...props} />;
}

type CardTitleProps = React.HTMLAttributes<HTMLHeadingElement> & {
    /** Heading level of the title; h2 sits directly under the page's h1, nest deeper cards with h3. */
    as?: 'h2' | 'h3' | 'h4' | 'p';
};

export function CardTitle({ as: Tag = 'h2', className, ...props }: CardTitleProps) {
    return (
        <Tag
            className={cn(
                'text-lg leading-none font-semibold tracking-tight text-foreground',
                className,
            )}
            {...props}
        />
    );
}

type CardDescriptionProps = React.HTMLAttributes<HTMLParagraphElement>;

export function CardDescription({ className, ...props }: CardDescriptionProps) {
    return <p className={cn('text-sm text-muted-foreground', className)} {...props} />;
}

type CardContentProps = React.HTMLAttributes<HTMLDivElement>;

export function CardContent({ className, ...props }: CardContentProps) {
    return <div className={cn('p-6 pt-0', className)} {...props} />;
}

type CardFooterProps = React.HTMLAttributes<HTMLDivElement>;

export function CardFooter({ className, ...props }: CardFooterProps) {
    return (
        <div className={cn('flex items-center border-t border-border p-6', className)} {...props} />
    );
}
