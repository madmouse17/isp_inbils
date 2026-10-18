import { useId } from 'react';
import * as ProgressPrimitive from '@radix-ui/react-progress';
import { cn } from '@/lib/utils';

export interface ProgressProps {
    /** Completed share, 0–100. */
    value: number;
    /** Names the operation, e.g. "Mengunggah bukti". Shown above the bar and used as its accessible name. */
    label: string;
    className?: string;
}

export function Progress({ value, label, className }: ProgressProps) {
    const labelId = useId();
    const percentage = Math.min(100, Math.max(0, Math.round(value)));

    return (
        <div className={cn('space-y-1.5', className)}>
            <div className="flex items-center justify-between gap-3 text-xs text-muted-foreground">
                <span id={labelId}>{label}</span>
                <span className="tabular-nums">{percentage}%</span>
            </div>
            <ProgressPrimitive.Root
                value={percentage}
                aria-labelledby={labelId}
                className="relative h-2 w-full overflow-hidden rounded-full bg-secondary"
            >
                <ProgressPrimitive.Indicator
                    className="h-full w-full bg-primary-text transition-transform duration-200 ease-out motion-reduce:transition-none"
                    style={{ transform: `translateX(-${100 - percentage}%)` }}
                />
            </ProgressPrimitive.Root>
        </div>
    );
}
