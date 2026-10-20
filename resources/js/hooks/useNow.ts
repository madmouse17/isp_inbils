import { useEffect, useState } from 'react';

/**
 * The current time in milliseconds, renewed every `intervalMs`. A page that shows many countdowns calls it once
 * and hands the value to each of them, so the page keeps one timer, not one per badge.
 */
export function useNow(intervalMs = 30_000): number {
    const [now, setNow] = useState(() => Date.now());

    useEffect(() => {
        const timer = window.setInterval(() => setNow(Date.now()), intervalMs);

        return () => window.clearInterval(timer);
    }, [intervalMs]);

    return now;
}
