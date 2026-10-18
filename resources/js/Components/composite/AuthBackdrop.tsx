import { lazy, Suspense } from 'react';

// The motion library stays out of the guest chunk: the form paints first, the backdrop fades in once loaded.
const Layers = lazy(() => import('./AuthBackdropLayers'));

export function AuthBackdrop() {
    return (
        <Suspense fallback={null}>
            <Layers />
        </Suspense>
    );
}
