import { cn } from '@/lib/utils';

interface TopbarProps {
    title?: string;
    left?: React.ReactNode;
    right?: React.ReactNode;
    className?: string;
}

export function Topbar({ title, left, right, className }: TopbarProps) {
    return (
        <header
            className={cn(
                'sticky top-0 z-30 flex h-16 items-center justify-between border-b border-border bg-background/80 px-4 backdrop-blur',
                className,
            )}
        >
            <div className="flex items-center gap-3">
                {left}
                {title && <h1 className="text-lg font-semibold text-foreground">{title}</h1>}
            </div>
            {right && <div className="flex items-center gap-3">{right}</div>}
        </header>
    );
}
