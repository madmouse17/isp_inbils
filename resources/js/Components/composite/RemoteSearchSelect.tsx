import { useEffect, useMemo, useRef, useState } from 'react';
import { router } from '@inertiajs/react';
import { SearchSelect } from '@/Components/ui/SearchSelect';
import type { SearchSelectOption, SearchSelectProps } from '@/Components/ui/SearchSelect';

/** An option as `App\Support\SearchOptions` sends it: every id is a ULID string. */
export interface RemoteOption {
    value: string;
    label: string;
    description?: string | null;
}

export interface RemoteSearchSelectProps extends Omit<
    SearchSelectProps,
    'options' | 'selected' | 'onSearch' | 'loading'
> {
    /**
     * The `$field` given to `SearchOptions::for()`: `customer` reads the page prop
     * `customerOptions` and searches by sending `customer_search`.
     */
    field: string;
    /** The page prop `<field>Options`. `undefined` until the first search, as Inertia sends it only on request. */
    options: RemoteOption[] | undefined;
    /** The option for the current `value` before any search (an edit form), built by the controller. */
    selected?: RemoteOption | null;
    /** Wait this long after the last key before asking the server. */
    debounceMs?: number;
}

const toOption = (option: RemoteOption): SearchSelectOption => ({
    value: option.value,
    label: option.label,
    description: option.description ?? undefined,
});

/**
 * A `SearchSelect` whose options come from the server, for tables that grow with the business
 * (customers, subscriptions, products). It never fetches by itself: when the list opens it reloads
 * the page's `<field>Options` prop with `<field>_recent=1` (the newest rows, under "Terakhir dipakai"),
 * and after the typed text settles with `<field>_search=<text>` (an Inertia partial reload), so the page
 * keeps its form state and scroll. The reload leaves the address bar alone.
 */
export function RemoteSearchSelect({
    field,
    options,
    selected,
    debounceMs = 300,
    minChars = 2,
    ...rest
}: RemoteSearchSelectProps) {
    const [loading, setLoading] = useState(false);
    const timer = useRef<number | undefined>(undefined);
    const cancelRequest = useRef<(() => void) | null>(null);
    const latest = useRef(0);
    // What `options` holds now: a typed search keeps the picker from reusing a recent list it no longer shows.
    const [holds, setHolds] = useState<'recent' | 'search' | null>(null);

    const mappedOptions = useMemo(() => (options ?? []).map(toOption), [options]);
    const mappedSelected = useMemo(() => (selected ? toOption(selected) : null), [selected]);

    const stop = () => {
        window.clearTimeout(timer.current);
        cancelRequest.current?.();
        cancelRequest.current = null;
        latest.current += 1;
        setLoading(false);
    };

    // Leaving the page with a search pending must not reload it afterwards.
    useEffect(
        () => () => {
            window.clearTimeout(timer.current);
            cancelRequest.current?.();
        },
        [],
    );

    /** `term` empty asks for the recent rows. */
    const ask = (term: string, delay: number) => {
        stop();
        setLoading(true);
        const request = latest.current;
        timer.current = window.setTimeout(() => {
            router.reload({
                only: [`${field}Options`],
                data: term === '' ? { [`${field}_recent`]: 1 } : { [`${field}_search`]: term },
                replace: true,
                // The search is not the page's address: a copied link or a reload must not carry it.
                preserveUrl: true,
                onCancelToken: (token) => {
                    cancelRequest.current = token.cancel;
                },
                onSuccess: () => {
                    setHolds(term === '' ? 'recent' : 'search');
                },
                // A newer search owns the spinner; a cancelled one must not switch it off.
                onFinish: () => {
                    if (latest.current === request) setLoading(false);
                },
            });
        }, delay);
    };

    const search = (query: string) => {
        const term = query.trim();
        if (term === '') {
            stop();
            if (holds !== 'recent') ask('', 0);

            return;
        }

        stop();
        if (term.length >= minChars) ask(term, debounceMs);
    };

    const open = () => {
        if (holds !== 'recent' || options === undefined) ask('', 0);
    };

    return (
        <SearchSelect
            {...rest}
            // The newest rows say nothing about the text typed since: they go while its answer is on its way.
            options={loading && holds === 'recent' ? [] : mappedOptions}
            selected={mappedSelected}
            onSearch={search}
            onOpen={open}
            recentHeading="Terakhir dipakai"
            loading={loading}
            minChars={minChars}
        />
    );
}
