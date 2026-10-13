import { cn } from '@/lib/utils';

/** Text wordmark in Figtree 700. A placeholder until the owner supplies a logo (antislop R-23): never draw a mark in its place. */
export function Wordmark({ className }: { className?: string }) {
    return (
        <span className={cn('font-bold tracking-tight text-foreground', className)}>Inbils</span>
    );
}
