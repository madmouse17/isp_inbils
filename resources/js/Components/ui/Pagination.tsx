import { ChevronLeftIcon, ChevronRightIcon } from '@heroicons/react/20/solid';
import { cn } from '@/lib/utils';

interface PaginationProps {
    currentPage: number | string;
    lastPage: number | string;
    onPageChange: (page: number) => void;
    className?: string;
}

export function Pagination({ currentPage, lastPage, onPageChange, className }: PaginationProps) {
    const current = toPageNumber(currentPage);
    const last = toPageNumber(lastPage);

    if (current === null || last === null || last <= 1) return null;

    const pages = buildWindow(current, last);

    return (
        <nav aria-label="Pagination" className={cn('flex items-center gap-1', className)}>
            <button
                onClick={() => onPageChange(current - 1)}
                disabled={current <= 1}
                className="inline-flex h-8 w-8 items-center justify-center rounded-md text-muted-foreground hover:bg-accent hover:text-accent-foreground disabled:cursor-not-allowed disabled:opacity-40"
                aria-label="Previous page"
            >
                <ChevronLeftIcon className="h-4 w-4" />
            </button>
            {pages.map((p, i) =>
                p === '...' ? (
                    <span key={`ellipsis-${i}`} className="px-1 text-sm text-muted-foreground">
                        ...
                    </span>
                ) : (
                    <button
                        key={p}
                        onClick={() => onPageChange(p)}
                        aria-current={p === current ? 'page' : undefined}
                        className={cn(
                            'inline-flex h-8 w-8 items-center justify-center rounded-lg text-sm font-medium',
                            p === current
                                ? 'bg-primary !text-white'
                                : 'text-foreground hover:bg-accent hover:text-accent-foreground',
                        )}
                    >
                        {p}
                    </button>
                ),
            )}
            <button
                onClick={() => onPageChange(current + 1)}
                disabled={current >= last}
                className="inline-flex h-8 w-8 items-center justify-center rounded-md text-muted-foreground hover:bg-accent hover:text-accent-foreground disabled:cursor-not-allowed disabled:opacity-40"
                aria-label="Next page"
            >
                <ChevronRightIcon className="h-4 w-4" />
            </button>
        </nav>
    );
}

function buildWindow(current: number, last: number): (number | '...')[] {
    const pages: (number | '...')[] = [];
    const set = new Set<number>();
    set.add(1);
    set.add(last);
    for (let i = Math.max(1, current - 2); i <= Math.min(last, current + 2); i++) set.add(i);

    let prev = 0;
    for (const p of [...set].sort((a, b) => a - b)) {
        if (p - prev > 1) pages.push('...');
        pages.push(p);
        prev = p;
    }
    return pages;
}

function toPageNumber(page: number | string): number | null {
    const parsedPage = Number(page);

    if (!Number.isInteger(parsedPage) || parsedPage < 1) {
        return null;
    }

    return parsedPage;
}
