import { router } from '@inertiajs/react';
import { useCallback, useEffect, useRef, useState } from 'react';

export type ServerTableFilterValue = string | number | boolean | null | undefined;
export type ServerTableFilters = Record<string, ServerTableFilterValue>;

export type UseServerTableOptions = {
    /** Inertia GET target (usually the current list path). */
    url?: string;
    /** Back-compat alias for older call sites. */
    baseUrl?: string;
    /** Initial filters from the server (page props). */
    filters?: ServerTableFilters;
    /** Back-compat alias for older call sites. */
    initialFilters?: ServerTableFilters;
    /** Debounce for search-driven visits (default 300ms). */
    debounceMs?: number;
    /** Inertia partial reload keys. */
    only?: string[];
    preserveState?: boolean;
    preserveScroll?: boolean;
};

export type UseServerTableResult = {
    filters: ServerTableFilters;
    /** Back-compat alias for older Pattern B call sites. */
    params: Record<string, string>;
    search: string;
    set: (key: string, value: ServerTableFilterValue) => void;
    setMany: (patch: ServerTableFilters) => void;
    setSearch: (value: string) => void;
    setFilter: (key: string, value: ServerTableFilterValue) => void;
    setFilters: (patch: ServerTableFilters) => void;
    /** Immediate visit with optional patch (no debounce). */
    visit: (patch?: ServerTableFilters) => void;
    /** True while an Inertia visit from this table is in flight. */
    processing: boolean;
    sortBy: string;
    sortDir: 'asc' | 'desc';
    onSort: (field: string, direction?: 'asc' | 'desc') => void;
    page: number;
    setPage: (page: number) => void;
    perPage: number;
    setPerPage: (perPage: number) => void;
};

function mergeFilters(base: ServerTableFilters, patch: ServerTableFilters): ServerTableFilters {
    const next: ServerTableFilters = { ...base, ...patch };

    // Filter/search changes should restart at page 1 unless page is explicit.
    if (!Object.prototype.hasOwnProperty.call(patch, 'page')) {
        if (Object.keys(patch).some((key) => key !== 'page')) {
            next.page = 1;
        }
    }

    return next;
}

/** Drop empty values so URLs stay clean. */
function toQuery(filters: ServerTableFilters): Record<string, string | number | boolean> {
    const query: Record<string, string | number | boolean> = {};

    for (const [key, value] of Object.entries(filters)) {
        if (value === null || value === undefined) continue;
        if (typeof value === 'string' && value.trim() === '') continue;
        query[key] = value;
    }

    return query;
}

/**
 * Server-driven list state for Inertia admin tables.
 * Debounces search, merges filters, and visits with preserveState.
 *
 * Local filter state is the source of truth after mount (Inertia preserveState).
 */
export function useServerTable({
    url,
    baseUrl,
    filters: serverFilters,
    initialFilters = {},
    debounceMs = 300,
    only,
    preserveState = true,
    preserveScroll = true,
}: UseServerTableOptions): UseServerTableResult {
    const targetUrl = url ?? baseUrl;
    if (!targetUrl) {
        throw new Error('useServerTable requires a url');
    }

    const mergedInitialFilters = { ...initialFilters, ...(serverFilters ?? {}) };

    const [filters, setFiltersState] = useState<ServerTableFilters>(() => ({
        ...mergedInitialFilters,
    }));
    const [processing, setProcessing] = useState(false);
    const filtersRef = useRef(filters);
    const timerRef = useRef<ReturnType<typeof setTimeout> | null>(null);

    useEffect(() => {
        filtersRef.current = filters;
    }, [filters]);

    const clearTimer = useCallback(() => {
        if (timerRef.current !== null) {
            clearTimeout(timerRef.current);
            timerRef.current = null;
        }
    }, []);

    useEffect(() => clearTimer, [clearTimer]);

    const visitNow = useCallback(
        (next: ServerTableFilters) => {
            setProcessing(true);
            router.get(targetUrl, toQuery(next), {
                preserveState,
                preserveScroll,
                replace: true,
                ...(only ? { only } : {}),
                onFinish: () => setProcessing(false),
            });
        },
        [only, preserveScroll, preserveState, targetUrl],
    );

    const apply = useCallback(
        (patch: ServerTableFilters, debounce: boolean) => {
            const next = mergeFilters(filtersRef.current, patch);
            filtersRef.current = next;
            setFiltersState(next);

            clearTimer();
            if (debounce) {
                timerRef.current = setTimeout(() => visitNow(next), debounceMs);
                return;
            }
            visitNow(next);
        },
        [clearTimer, debounceMs, visitNow],
    );

    const visit = useCallback(
        (patch: ServerTableFilters = {}) => {
            apply(patch, false);
        },
        [apply],
    );

    const setSearch = useCallback(
        (value: string) => {
            apply({ search: value }, true);
        },
        [apply],
    );

    const setFilter = useCallback(
        (key: string, value: ServerTableFilterValue) => {
            apply({ [key]: value }, key === 'search');
        },
        [apply],
    );

    const setFilters = useCallback(
        (patch: ServerTableFilters) => {
            const debounce = Object.keys(patch).length === 1 && 'search' in patch;
            apply(patch, debounce);
        },
        [apply],
    );

    const set = useCallback(
        (key: string, value: ServerTableFilterValue) => {
            setFilter(key, value);
        },
        [setFilter],
    );

    const setMany = useCallback(
        (patch: ServerTableFilters) => {
            setFilters(patch);
        },
        [setFilters],
    );

    const sortBy = String(filters.sort ?? '');
    const sortDir: 'asc' | 'desc' = filters.direction === 'desc' ? 'desc' : 'asc';

    const onSort: UseServerTableResult['onSort'] = useCallback(
        (field: string, direction = 'asc') => {
            apply({ sort: field, direction }, false);
        },
        [apply],
    );

    const params = Object.fromEntries(
        Object.entries(filters).flatMap(([key, value]) => {
            if (value === null || value === undefined || value === '') return [];
            return [[key, String(value)]] as const;
        }),
    ) as Record<string, string>;

    const page = Number(filters.page ?? 1);
    const perPage = Number(filters.per_page ?? 15);

    const setPage = useCallback(
        (nextPage: number) => {
            apply({ page: nextPage }, false);
        },
        [apply],
    );

    const setPerPage = useCallback(
        (nextPerPage: number) => {
            apply({ per_page: nextPerPage, page: 1 }, false);
        },
        [apply],
    );

    return {
        filters,
        params,
        search: String(filters.search ?? ''),
        set,
        setMany,
        setSearch,
        setFilter,
        setFilters,
        visit,
        processing,
        sortBy,
        sortDir,
        onSort,
        page,
        setPage,
        perPage,
        setPerPage,
    };
}

export default useServerTable;
