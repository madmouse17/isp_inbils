import type { ReactNode } from 'react';

interface DashboardRegionProps {
    id: string;
    title: string;
    /** Sits next to the heading, for example a count. */
    meta?: ReactNode;
    children: ReactNode;
}

export function DashboardRegion({ id, title, meta, children }: DashboardRegionProps) {
    return (
        <section aria-labelledby={id} className="space-y-3 min-w-0">
            <div className="flex flex-wrap items-center gap-2">
                <h2 id={id} className="text-lg font-semibold tracking-tight text-foreground">
                    {title}
                </h2>
                {meta}
            </div>
            {children}
        </section>
    );
}
