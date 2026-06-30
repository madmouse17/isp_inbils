import { cn } from '@/lib/utils';

interface SkeletonProps {
    className?: string;
}

export function Skeleton({ className }: SkeletonProps) {
    return (
        <div className={cn('animate-pulse rounded-md bg-surface-200 dark:bg-surface-800', className)} />
    );
}
