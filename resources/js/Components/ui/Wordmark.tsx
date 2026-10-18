import { cn } from '@/lib/utils';

/** Text wordmark in Figtree 700. Pair it with `BrandMark` (the signal glyph) through `BrandLockup` where the brand is introduced. */
export function Wordmark({ className }: { className?: string }) {
    return (
        <span className={cn('font-bold tracking-tight text-foreground', className)}>Inbils</span>
    );
}
