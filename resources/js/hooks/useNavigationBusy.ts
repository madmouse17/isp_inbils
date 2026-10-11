import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';

/** Same delay as the Inertia progress bar, so the bar and the busy state appear together. */
export const NAVIGATION_FEEDBACK_DELAY_MS = 250;

type NavigationVisit = {
    method: string;
    async: boolean;
    prefetch: boolean;
    showProgress: boolean;
    preserveState: unknown;
};

/**
 * A navigation replaces the page: a visible GET that is neither a prefetch, an async reload,
 * nor a state-preserving refetch (those are covered by the table and form feedback).
 */
function isNavigation(visit: NavigationVisit): boolean {
    return (
        visit.method === 'get' &&
        !visit.async &&
        !visit.prefetch &&
        visit.showProgress &&
        visit.preserveState !== true
    );
}

/**
 * True while a page navigation has been in flight for longer than the progress bar delay.
 * Fast navigations never flip it, and a visit that interrupts another keeps it steady.
 */
export function useNavigationBusy(): boolean {
    const [busy, setBusy] = useState(false);

    useEffect(() => {
        const active = new Set<unknown>();
        let timer: ReturnType<typeof setTimeout> | undefined;
        let shown = false;

        const show = (value: boolean) => {
            shown = value;
            setBusy(value);
        };

        const offStart = router.on('start', (event) => {
            const { visit } = event.detail;
            if (!isNavigation(visit)) return;

            active.add(visit);
            clearTimeout(timer);
            if (!shown) {
                timer = setTimeout(() => show(true), NAVIGATION_FEEDBACK_DELAY_MS);
            }
        });

        const offFinish = router.on('finish', (event) => {
            if (!active.delete(event.detail.visit) || active.size > 0) return;

            clearTimeout(timer);
            if (shown) {
                // Deferred one tick: Inertia finishes the interrupted visit before starting its replacement.
                timer = setTimeout(() => show(false));
            }
        });

        return () => {
            offStart();
            offFinish();
            clearTimeout(timer);
        };
    }, []);

    return busy;
}
