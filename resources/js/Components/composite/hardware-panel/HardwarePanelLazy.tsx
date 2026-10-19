import { lazy, Suspense } from 'react';
import { Skeleton } from '@/Components/ui/Skeleton';
import type { HardwarePanelProps } from './HardwarePanel';
import type { RackPanelProps } from './RackPanel';
import type { TracePanelProps } from './TracePanel';

// The three panels are the only modules that import the drawing code (symbols, geometry, tooltip);
// loading them here keeps all of it out of every page that has no panel. Pages import these
// wrappers, never the panels themselves.
const HardwarePanel = lazy(() =>
    import('./HardwarePanel').then((module) => ({ default: module.HardwarePanel })),
);
const RackPanel = lazy(() =>
    import('./RackPanel').then((module) => ({ default: module.RackPanel })),
);
const TracePanel = lazy(() =>
    import('./TracePanel').then((module) => ({ default: module.TracePanel })),
);

/** Stands in for a panel while its code or its data loads. */
export function PanelSkeleton({ label = 'Memuat panel perangkat' }: { label?: string }) {
    return (
        <div role="status" aria-label={label} className="space-y-3">
            <Skeleton className="h-5 w-2/3 max-w-md" />
            <Skeleton className="h-32 w-full" />
        </div>
    );
}

export function HardwarePanelLazy(props: HardwarePanelProps) {
    return (
        <Suspense fallback={<PanelSkeleton />}>
            <HardwarePanel {...props} />
        </Suspense>
    );
}

export function RackPanelLazy(props: RackPanelProps) {
    return (
        <Suspense fallback={<PanelSkeleton />}>
            <RackPanel {...props} />
        </Suspense>
    );
}

export function TracePanelLazy(props: TracePanelProps) {
    return (
        <Suspense fallback={<PanelSkeleton label="Memuat jalur fiber" />}>
            <TracePanel {...props} />
        </Suspense>
    );
}
