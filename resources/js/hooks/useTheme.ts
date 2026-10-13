import { useCallback, useSyncExternalStore } from 'react';

const STORAGE_KEY = 'darkMode';

function subscribe(onChange: () => void) {
    const observer = new MutationObserver(onChange);
    observer.observe(document.documentElement, { attributes: true, attributeFilter: ['class'] });

    return () => observer.disconnect();
}

/**
 * The theme is the `dark` class on <html>. The inline script in app.blade.php sets it from the saved
 * choice (or the system one) before first paint; this hook reads it and switches it.
 */
export function useTheme() {
    const dark = useSyncExternalStore(
        subscribe,
        () => document.documentElement.classList.contains('dark'),
        () => false,
    );

    const toggle = useCallback(() => {
        const next = !document.documentElement.classList.contains('dark');

        document.documentElement.classList.toggle('dark', next);
        localStorage.setItem(STORAGE_KEY, String(next));
    }, []);

    return { dark, toggle };
}
