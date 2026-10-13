import { cn } from '@/lib/utils';

interface TopbarProps {
    title?: string;
    left?: React.ReactNode;
    right?: React.ReactNode;
    className?: string;
}

/** The page's own heading is the one h1; the title here is context for the small screen, so it is not a heading. */
export function Topbar({ title, left, right, className }: TopbarProps) {
    return (
        <header
            className={cn(
                'sticky top-0 z-30 flex h-14 items-center justify-between border-b border-border bg-background px-4 md:px-6',
                className,
            )}
        >
            <div className="flex min-w-0 items-center gap-3">
                {left}
                {title && (
                    <p className="truncate text-sm font-medium text-foreground md:text-base">
                        {title}
                    </p>
                )}
            </div>
            {right && <div className="flex items-center gap-3">{right}</div>}
        </header>
    );
}
