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
                orientation === 'horizontal' ? 'h-px w-full bg-border' : 'h-full w-px bg-border',
                className,
            )}
        />
    );
}
