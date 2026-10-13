import { cn } from '@/lib/utils';

/**
 * The placeholder for a value that does not exist: a plain hyphen (never an em dash), announced as
 * "Kosong" so a screen reader does not read out a punctuation mark.
 */
export function EmptyCell({ className }: { className?: string }) {
    return (
        <span className={cn('text-muted-foreground', className)}>
            <span aria-hidden="true">-</span>
            <span className="sr-only">Kosong</span>
        </span>
    );
}
