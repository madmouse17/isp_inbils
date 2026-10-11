import { Link } from '@inertiajs/react';
import { Button } from '@/Components/ui';

/** Drill-down URLs of a report page keyed by figure; only the lists the viewer may open are present. */
export type DrillLinks = Record<string, string>;

interface DrillLinkProps {
    /** URL the server built for the figure; absent when the viewer may not open its source rows. */
    href: string | undefined;
    /** Name of the figure, so each link has its own accessible name. */
    label: string;
    /** The row of a breakdown (a status, a category) when the figure is one. */
    group?: string;
}

export function DrillLink({ href, label, group }: DrillLinkProps) {
    if (!href) {
        return null;
    }

    const target =
        group === undefined
            ? href
            : `${href}${href.includes('?') ? '&' : '?'}group=${encodeURIComponent(group)}`;

    return (
        <Button asChild variant="ghost" size="sm" className="mt-1 -ml-3">
            <Link href={target} aria-label={`View records behind ${label}`}>
                View records
            </Link>
        </Button>
    );
}
