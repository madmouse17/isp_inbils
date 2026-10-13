import { lazy, Suspense } from 'react';
import type { BarChartProps, DonutChartProps, LineChartProps } from './Chart';

// Chart.tsx is the only module that imports recharts; loading it here keeps recharts out of every
// page that has no chart. Pages use these wrappers, never Chart.tsx.
const LineChart = lazy(() => import('./Chart').then((module) => ({ default: module.LineChart })));
const BarChart = lazy(() => import('./Chart').then((module) => ({ default: module.BarChart })));
const DonutChart = lazy(() => import('./Chart').then((module) => ({ default: module.DonutChart })));

/** Keep in step with DEFAULT_HEIGHT in Chart.tsx. */
const DEFAULT_HEIGHT = 280;

function ChartSkeleton({ height = DEFAULT_HEIGHT }: { height?: number }) {
    return (
        <div
            role="status"
            aria-label="Memuat grafik"
            className="animate-pulse rounded-md bg-muted motion-reduce:animate-none"
            style={{ height }}
        />
    );
}

export function LineChartLazy(props: LineChartProps) {
    return (
        <Suspense fallback={<ChartSkeleton height={props.height} />}>
            <LineChart {...props} />
        </Suspense>
    );
}

export function BarChartLazy(props: BarChartProps) {
    return (
        <Suspense fallback={<ChartSkeleton height={props.height} />}>
            <BarChart {...props} />
        </Suspense>
    );
}

export function DonutChartLazy(props: DonutChartProps) {
    return (
        <Suspense fallback={<ChartSkeleton height={props.height} />}>
            <DonutChart {...props} />
        </Suspense>
    );
}
