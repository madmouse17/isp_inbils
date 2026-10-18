import { Wordmark } from '@/Components/ui/Wordmark';
import { cn } from '@/lib/utils';
import type { SVGProps } from 'react';

/**
 * Signal glyph (dot + three quarter arcs), redrawn from the mobile apps' splash lockup.
 * Paints with currentColor. Decorative by default; pass `title` when it stands alone.
 */
export function BrandMark({
    title,
    className,
    ...props
}: Omit<SVGProps<SVGSVGElement>, 'children'> & { title?: string }) {
    return (
        <svg
            viewBox="0 0 200 200"
            fill="none"
            role={title ? 'img' : undefined}
            aria-hidden={title ? undefined : true}
            aria-label={title}
            className={cn('size-12 shrink-0', className)}
            {...props}
        >
            <circle cx="26" cy="175" r="24" fill="currentColor" />
            <g stroke="currentColor" strokeWidth="33" strokeLinecap="round">
                <path d="M26 117A58 58 0 0 1 84 175" />
                <path d="M26 67A108 108 0 0 1 134 175" />
                <path d="M26 18A157 157 0 0 1 183 175" />
            </g>
        </svg>
    );
}

/** Glyph above the wordmark on narrow screens, beside it from `sm`. */
export function BrandLockup({ className }: { className?: string }) {
    return (
        <span className={cn('flex flex-col items-center gap-2 sm:flex-row sm:gap-3', className)}>
            <BrandMark className="size-12 text-brand-600 dark:text-brand-400" />
            <Wordmark className="text-3xl" />
        </span>
    );
}
