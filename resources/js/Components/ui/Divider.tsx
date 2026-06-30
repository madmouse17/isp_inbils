import { cn } from '@/lib/utils';

interface DividerProps {
    orientation?: 'horizontal' | 'vertical';
    className?: string;
}

export function Divider({ orientation = 'horizontal', className }: DividerProps) {
    return (
        <div
            role="separator"
            className={cn(
                orientation === 'horizontal'
                    ? 'h-px w-full bg-surface-200 dark:bg-surface-800'
                    : 'h-full w-px bg-surface-200 dark:bg-surface-800',
                className,
            )}
        />
    );
}
