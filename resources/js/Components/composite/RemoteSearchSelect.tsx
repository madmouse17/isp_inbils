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
 * (customers, subscriptions, products). It never fetches by itself: after the typed text settles it
 * reloads the page's `<field>Options` prop with `<field>_search=<text>` (an Inertia partial reload),
 * so the page keeps its form state and scroll. The reload puts `?<field>_search=` in the address bar.
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

    const search = (query: string) => {
        stop();
        if (query.trim().length < minChars) return;

        setLoading(true);
        const request = latest.current;
        timer.current = window.setTimeout(() => {
            router.reload({
                only: [`${field}Options`],
                data: { [`${field}_search`]: query.trim() },
                replace: true,
                onCancelToken: (token) => {
                    cancelRequest.current = token.cancel;
                },
                // A newer search owns the spinner; a cancelled one must not switch it off.
                onFinish: () => {
                    if (latest.current === request) setLoading(false);
                },
            });
        }, debounceMs);
    };

    return (
        <SearchSelect
            {...rest}
            options={mappedOptions}
            selected={mappedSelected}
            onSearch={search}
            loading={loading}
            minChars={minChars}
        />
    );
}
