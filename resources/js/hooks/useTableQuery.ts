import { router } from '@inertiajs/react';
import { useCallback, useEffect, useRef, useState } from 'react';

type Options = {
    only?: string[];
    /** Debounce for free-text search (ms). Default 300. */
    debounceMs?: number;
};

/**
 * Shared Inertia list-query helper for index pages.
 * Keeps filters in local state and pushes them to the current URL.
 * Pattern B: exposes sortBy / sortDir / onSort (query keys sort_by / sort_dir).
 */
export function useTableQuery(
    initial: Record<string, string | number | undefined | null> = {},
    options: Options = {},
) {
    const debounceMs = options.debounceMs ?? 300;
    const [params, setParams] = useState<Record<string, string>>(() => {
        const out: Record<string, string> = {};
        for (const [k, v] of Object.entries(initial)) {
            if (v !== undefined && v !== null && String(v) !== '') {
                out[k] = String(v);
            }
        }
        return out;
    });

    const timer = useRef<ReturnType<typeof setTimeout> | null>(null);
    const first = useRef(true);

    const visit = useCallback(
        (next: Record<string, string>) => {
            const clean: Record<string, string> = {};
            for (const [k, v] of Object.entries(next)) {
                if (v !== undefined && v !== null && String(v) !== '') {
                    clean[k] = String(v);
                }
            }
            router.get(window.location.pathname, clean, {
                preserveState: true,
                preserveScroll: true,
                replace: true,
                ...(options.only ? { only: options.only } : {}),
            });
        },
        [options.only],
    );

    useEffect(() => {
        if (first.current) {
            first.current = false;
            return;
        }
        if (timer.current) clearTimeout(timer.current);
        timer.current = setTimeout(() => visit(params), debounceMs);
        return () => {
            if (timer.current) clearTimeout(timer.current);
        };
    }, [params, visit, debounceMs]);

    const set = useCallback((key: string, value: string | number | undefined | null) => {
        setParams((prev) => {
            const next = { ...prev };
            if (value === undefined || value === null || String(value) === '') {
                delete next[key];
            } else {
                next[key] = String(value);
            }
            if (key !== 'page') delete next.page;
            return next;
        });
    }, []);

    const setMany = useCallback((patch: Record<string, string | number | undefined | null>) => {
        setParams((prev) => {
            const next = { ...prev };
            for (const [k, v] of Object.entries(patch)) {
                if (v === undefined || v === null || String(v) === '') delete next[k];
                else next[k] = String(v);
            }
            delete next.page;
            return next;
        });
    }, []);

    const sortBy = params.sort_by ?? '';
    const sortDir: 'asc' | 'desc' = params.sort_dir === 'desc' ? 'desc' : 'asc';

    const onSort = useCallback((key: string) => {
        setParams((prev) => {
            const curKey = prev.sort_by ?? '';
            const curDir = prev.sort_dir === 'desc' ? 'desc' : 'asc';
            const next: Record<string, string> = { ...prev };
            delete next.page;
            if (curKey === key) {
                next.sort_by = key;
                next.sort_dir = curDir === 'asc' ? 'desc' : 'asc';
            } else {
                next.sort_by = key;
                next.sort_dir = 'asc';
            }
            return next;
        });
    }, []);

    return { params, set, setMany, sortBy, sortDir, onSort };
}
