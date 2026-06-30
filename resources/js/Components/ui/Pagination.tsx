import { ChevronLeftIcon, ChevronRightIcon } from '@heroicons/react/20/solid';
import { cn } from '@/lib/utils';

interface PaginationProps {
    currentPage: number;
    lastPage: number;
    onPageChange: (page: number) => void;
    className?: string;
}

export function Pagination({ currentPage, lastPage, onPageChange, className }: PaginationProps) {
    const pages = buildWindow(currentPage, lastPage);

    if (lastPage <= 1) return null;

    return (
        <nav aria-label="Pagination" className={cn('flex items-center gap-1', className)}>
            <button
                onClick={() => onPageChange(currentPage - 1)}
                disabled={currentPage <= 1}
                className="inline-flex h-8 w-8 items-center justify-center rounded-lg text-surface-500 hover:bg-surface-100 disabled:opacity-40 disabled:cursor-not-allowed dark:hover:bg-surface-800"
                aria-label="Previous page"
            >
                <ChevronLeftIcon className="h-4 w-4" />
            </button>
            {pages.map((p, i) =>
                p === '...' ? (
                    <span key={`ellipsis-${i}`} className="px-1 text-sm text-surface-400">...</span>
                ) : (
                    <button
                        key={p}
                        onClick={() => onPageChange(p as number)}
                        aria-current={p === currentPage ? 'page' : undefined}
                        className={cn(
                            'inline-flex h-8 w-8 items-center justify-center rounded-lg text-sm font-medium',
                            p === currentPage
                                ? 'bg-brand-600 text-white'
                                : 'text-surface-700 hover:bg-surface-100 dark:text-surface-300 dark:hover:bg-surface-800',
                        )}
                    >
                        {p}
                    </button>
                ),
            )}
            <button
                onClick={() => onPageChange(currentPage + 1)}
                disabled={currentPage >= lastPage}
                className="inline-flex h-8 w-8 items-center justify-center rounded-lg text-surface-500 hover:bg-surface-100 disabled:opacity-40 disabled:cursor-not-allowed dark:hover:bg-surface-800"
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
