import { useCallback, useRef, useState } from 'react';

export type ListVisitState = {
    /** Debounce window or visit in flight. Drives loading feedback (skeleton rows, status text). */
    processing: boolean;
    /** Visit in flight only. Use it to disable controls: disabling a focused input mid-typing drops focus. */
    visiting: boolean;
    /** A change is waiting behind the debounce timer. */
    queue: () => void;
    /** A visit is about to start. Returns the `onFinish` callback for that visit. */
    begin: () => () => void;
};

/**
 * Busy flags shared by the server-table hooks.
 * `processing` turns on at the first change and only turns off once the last visit finished
 * with no newer change waiting, so an interrupted visit never clears feedback early.
 */
export function useListVisitState(): ListVisitState {
    const [processing, setProcessing] = useState(false);
    const [visiting, setVisiting] = useState(false);
    const latestVisit = useRef(0);
    const queued = useRef(false);

    const queue = useCallback(() => {
        queued.current = true;
        setProcessing(true);
    }, []);

    const begin = useCallback(() => {
        queued.current = false;
        const id = ++latestVisit.current;
        setProcessing(true);
        setVisiting(true);

        return () => {
            if (id !== latestVisit.current) return;
            setVisiting(false);
            if (!queued.current) setProcessing(false);
        };
    }, []);

    return { processing, visiting, queue, begin };
}
