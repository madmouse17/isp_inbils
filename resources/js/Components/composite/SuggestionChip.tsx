import { Button } from '@/Components/ui';
import { cn } from '@/lib/utils';

interface SuggestionChipProps {
    /** What the chip says before the value, e.g. `Gunakan` or `Gunakan POP terdekat`. */
    label?: string;
    /** The suggested value as the form would show it. */
    value: string;
    /** Fills the field; the chip is shown only while the field is empty, so nothing typed is overwritten. */
    onUse: () => void;
    className?: string;
}

/**
 * One value the system computed for a field. A real button: reachable by keyboard, 44px on touch (the
 * `Button` small size). Show it only for a value derived from the app's own data, never a guess.
 */
export function SuggestionChip({
    label = 'Gunakan',
    value,
    onUse,
    className,
}: SuggestionChipProps) {
    return (
        <Button
            type="button"
            size="sm"
            variant="outline"
            aria-label={`Gunakan saran: ${value}`}
            onClick={onUse}
            className={cn(
                'h-auto min-h-11 max-w-full py-1 whitespace-normal sm:min-h-8',
                className,
            )}
        >
            <span className="font-normal">{label}:</span>
            <span className="font-mono break-all">{value}</span>
        </Button>
    );
}
