import { Button } from '@/Components/ui/Button';
import type { PaginationMeta } from '@/types';
import { cn } from '@/lib/utils';

export interface PaginationProps {
    meta?: PaginationMeta | null;
    currentPage?: number;
    lastPage?: number;
    total?: number;
    from?: number | null;
    to?: number | null;
    onPageChange: (page: number) => void;
    /** Disables both buttons while the next page is loading. */
    disabled?: boolean;
    className?: string;
}

export function Pagination({
    meta,
    currentPage,
    lastPage,
    total,
    from,
    to,
    onPageChange,
    disabled = false,
    className,
}: PaginationProps) {
    const page = meta?.current_page ?? currentPage ?? 1;
    const last = meta?.last_page ?? lastPage ?? 1;
    const totalCount = meta?.total ?? total ?? 0;
    const rangeFrom = meta?.from ?? from ?? null;
    const rangeTo = meta?.to ?? to ?? null;

    if (last <= 1 && totalCount === 0) {
        return null;
    }

    return (
        <div
            className={cn(
                'flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between',
                className,
            )}
        >
            <p className="text-sm text-muted-foreground">
                {rangeFrom != null && rangeTo != null
                    ? `Showing ${rangeFrom}–${rangeTo} of ${totalCount}`
                    : `${totalCount} total`}
            </p>
            <div className="flex items-center gap-2">
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    disabled={disabled || page <= 1}
                    onClick={() => onPageChange(page - 1)}
                >
                    Previous
                </Button>
                <span className="text-sm text-muted-foreground">
                    Page {page} of {last}
                </span>
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    disabled={disabled || page >= last}
                    onClick={() => onPageChange(page + 1)}
                >
                    Next
                </Button>
            </div>
        </div>
    );
}

export default Pagination;
