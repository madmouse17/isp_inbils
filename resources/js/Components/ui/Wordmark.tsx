import logoPng from '../../../images/brand/inbils-logo.png';
import logoWebp from '../../../images/brand/inbils-logo.webp';
import markPng from '../../../images/brand/inbils-mark.png';
import markWebp from '../../../images/brand/inbils-mark.webp';
import { cn } from '@/lib/utils';

/**
 * Inbils logo (gradient wordmark), the product brand on every guest page and the default for a company
 * without its own logo. The caller sets the height (`className="h-10"`); the width follows the 504x160
 * aspect ratio. WebP with a PNG fallback; both are 2x for up to 80 px tall.
 */
export function Wordmark({ className }: { className?: string }) {
    return (
        <picture>
            <source srcSet={logoWebp} type="image/webp" />
            <img
                src={logoPng}
                alt="Inbils"
                width={504}
                height={160}
                className={cn('h-10 w-auto select-none', className)}
            />
        </picture>
    );
}

/**
 * The orbit dot of the "i" as a square mark, for square slots such as the sidebar tile. Decorative:
 * the brand name is always written next to it.
 */
export function InbilsMark({ className }: { className?: string }) {
    return (
        <picture>
            <source srcSet={markWebp} type="image/webp" />
            <img
                src={markPng}
                alt=""
                width={72}
                height={72}
                className={cn('size-full object-contain', className)}
            />
        </picture>
    );
}
