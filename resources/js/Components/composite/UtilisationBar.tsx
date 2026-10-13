import { cn } from '@/lib/utils';
import { formatPercent } from '@/lib/ipam';

export interface UtilisationBarProps {
    /** Share used, 0–100. Null means the figure is not measured and renders a dash. */
    percent: number | null;
    /** What is measured, e.g. "10.20.0.0/22 addresses in use". It names the meter for assistive technology. */
    label: string;
    className?: string;
}

/** Fill colours are the 700 (light) and 400 (dark) steps: at least 4.5:1 against the track in both themes. */
function tone(percent: number): string {
    if (percent >= 90) return 'bg-red-700 dark:bg-red-400';
    if (percent >= 75) return 'bg-amber-700 dark:bg-amber-400';

    return 'bg-emerald-700 dark:bg-emerald-400';
}

export function UtilisationBar({ percent, label, className }: UtilisationBarProps) {
    if (percent === null) {
        return (
            <span className="text-muted-foreground" title={`${label}: not measured`}>
                <span aria-hidden="true">-</span>
                <span className="sr-only">{label}: not measured</span>
            </span>
        );
    }

    const value = Math.min(100, Math.max(0, percent));
    const text = `${formatPercent(value)}%`;

    return (
        <div className={cn('flex items-center gap-2', className)}>
            <div
                role="meter"
                aria-label={label}
                aria-valuemin={0}
                aria-valuemax={100}
                aria-valuenow={value}
                aria-valuetext={text}
                className="h-2 w-24 shrink-0 overflow-hidden rounded-full bg-secondary ring-1 ring-border ring-inset"
            >
                <div className={cn('h-full', tone(value))} style={{ width: `${value}%` }} />
            </div>
            <span className="w-12 text-right text-xs tabular-nums">{text}</span>
        </div>
    );
}
