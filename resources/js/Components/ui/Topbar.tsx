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
                'sticky top-0 z-30 flex h-16 items-center justify-between border-b border-surface-200 bg-white/80 px-4 backdrop-blur dark:border-surface-800 dark:bg-surface-900/80',
                className,
            )}
        >
            <div className="flex items-center gap-3">
                {left}
                {title && <h1 className="text-lg font-semibold text-surface-900 dark:text-surface-100">{title}</h1>}
            </div>
            {right && <div className="flex items-center gap-3">{right}</div>}
        </header>
    );
}
