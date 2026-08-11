import type { PaginatorShape } from '@/Components/composite';

export interface PaginationLike<T> {
    data: T[];
    meta?: Partial<PaginatorShape<T>>;
    current_page?: number;
    last_page?: number;
    per_page?: number;
    total?: number;
}

export function toPagination<T>(page: PaginationLike<T>): PaginatorShape<T> {
    const meta = page.meta ?? page;

    return {
        data: page.data,
        current_page: meta.current_page ?? 1,
        last_page: meta.last_page ?? 1,
        per_page: meta.per_page ?? Math.max(page.data.length, 1),
        total: meta.total ?? page.data.length,
    };
}
