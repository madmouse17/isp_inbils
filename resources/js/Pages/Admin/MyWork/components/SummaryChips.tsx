import { cn } from '@/lib/utils';
import { formatNumber } from '@/lib/format';

export interface SummaryChip {
    key: string;
    label: string;
    count: number;
    /** The filter this chip stands for is on. */
    active?: boolean;
    /** Filters the list to this chip; without it the chip only reports the count. */
    onSelect?: () => void;
}

interface SummaryChipsProps {
    /** Names the group for assistive technology, for example "Ringkasan antrean penugasan". */
    label: string;
    chips: SummaryChip[];
}

const chipClass =
    'inline-flex h-11 items-center gap-2 rounded-md border px-3 text-sm transition-colors focus-visible:ring-1 focus-visible:ring-ring focus-visible:outline-hidden motion-reduce:transition-none sm:h-9 sm:pointer-coarse:h-11';

/**
 * Counts of a queue as one row of chips in one hue. A chip that can filter is a toggle button; one
 * that cannot is plain text, so nothing looks clickable that is not.
 */
export function SummaryChips({ label, chips }: SummaryChipsProps) {
    return (
        <div role="group" aria-label={label} className="flex flex-wrap gap-2">
            {chips.map((chip) => {
                const content = (
                    <>
                        <span>{chip.label}</span>
                        <span className="font-semibold tabular-nums">
                            {formatNumber(chip.count)}
                        </span>
                    </>
                );

                if (!chip.onSelect) {
                    return (
                        <span
                            key={chip.key}
                            className={cn(chipClass, 'border-border bg-muted text-foreground')}
                        >
                            {content}
                        </span>
                    );
                }

                return (
                    <button
                        key={chip.key}
                        type="button"
                        aria-pressed={chip.active ?? false}
                        onClick={chip.onSelect}
                        className={cn(
                            chipClass,
                            chip.active
                                ? 'border-primary bg-primary text-primary-foreground'
                                : 'border-input bg-background text-foreground hover:bg-accent',
                        )}
                    >
                        {content}
                    </button>
                );
            })}
        </div>
    );
}
